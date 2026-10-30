<?php

namespace App\Services\Wallet;

use App\Models\Event;
use App\Models\WalletPass;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * An Apple Wallet event ticket (.pkpass), built in plain PHP (plan section 10):
 *
 *   pass.json      the fields, colors, barcode, relevant date and location
 *   icon/strip     PassImages
 *   manifest.json  SHA-1 of every file
 *   signature      detached PKCS #7 of the manifest (DER), signed with the Pass
 *                  Type ID certificate and the Apple WWDR intermediate
 *
 * all zipped together. The update web service (webServiceURL, device
 * registration, passesUpdatedSince, APNs) is a later step: the serial and
 * authenticationToken it needs are stored in wallet_passes already.
 */
class ApplePass
{
    public const MIME = 'application/vnd.apple.pkpass';

    public function __construct(private PassImages $images) {}

    public function configured(): bool
    {
        $c = config('wallet.apple');

        return filled($c['pass_type_id'] ?? null) && filled($c['team_id'] ?? null)
            && is_file((string) ($c['cert_path'] ?? '')) && is_file((string) ($c['wwdr_path'] ?? ''));
    }

    /**
     * The .pkpass bytes.
     */
    public function build(Event $event, PassData $data, WalletPass $pass): string
    {
        $files = ['pass.json' => json_encode($this->passJson($data, $pass), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]
            + $this->images->files($event, $data);

        // one line: S/MIME tools that verify it never re-wrap line endings
        $manifest = json_encode(static::manifest($files), JSON_UNESCAPED_SLASHES);
        $files['manifest.json'] = $manifest;
        $files['signature'] = $this->sign($manifest);

        return static::zip($files);
    }

    public function passJson(PassData $data, WalletPass $pass): array
    {
        $c = config('wallet.apple');
        $t = fn (string $key) => trans("wallet.pass.{$key}", [], $data->locale);
        // Arabic reads right to left: values and labels aligned to the right
        $align = $data->rtl() ? 'PKTextAlignmentRight' : 'PKTextAlignmentLeft';
        $field = fn (string $key, string $label, ?string $value) => $value === null || $value === '' ? null
            : ['key' => $key, 'label' => $label, 'value' => $value, 'textAlignment' => $align];

        $json = [
            'formatVersion' => 1,
            'passTypeIdentifier' => $c['pass_type_id'],
            'teamIdentifier' => $c['team_id'],
            'serialNumber' => $pass->serial,
            'authenticationToken' => $pass->authentication_token,
            'organizationName' => $c['organization'],
            'description' => $data->eventName,
            'logoText' => $data->occasion,
            'backgroundColor' => PassData::rgb($data->colors['background']),
            'foregroundColor' => PassData::rgb($data->colors['foreground']),
            'labelColor' => PassData::rgb($data->colors['label']),
            'sharingProhibited' => true,
            'eventTicket' => [
                'primaryFields' => array_values(array_filter([$field('guest', $t('guest'), $data->guest)])),
                'secondaryFields' => array_values(array_filter([
                    $field('date', $t('date'), $data->date),
                    $field('time', $t('time'), $data->time),
                ])),
                'auxiliaryFields' => array_values(array_filter([
                    $field('hall', $t('hall'), $data->hall),
                    $field('table', $t('table'), $data->table),
                    $field('seats', $t('seats'), $data->seatsLabel()),
                ])),
                'backFields' => array_values(array_filter([
                    $field('event', $t('event'), $data->eventName),
                    $field('address', $t('address'), $data->address),
                    $field('link', $t('link'), $data->url),
                    $field('note', $t('note'), $t('note_text')),
                ])),
            ],
            'barcodes' => [[
                'format' => 'PKBarcodeFormatQR',
                'message' => $data->url,
                'messageEncoding' => 'iso-8859-1',
                'altText' => $t('show_at_door'),
            ]],
        ];

        if ($data->start) {
            $json['relevantDate'] = $data->start;
        }
        if ($data->lat !== null && $data->lng !== null) {
            $json['locations'] = [[
                'latitude' => $data->lat,
                'longitude' => $data->lng,
                'relevantText' => trans('wallet.pass.relevant', ['hall' => $data->hall ?? ''], $data->locale),
            ]];
        }

        return $json;
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, string> file name => SHA-1
     */
    public static function manifest(array $files): array
    {
        return array_map(fn (string $bytes) => sha1($bytes), $files);
    }

    /**
     * Detached PKCS #7 signature of the manifest, DER-encoded.
     */
    public function sign(string $manifest): string
    {
        $c = config('wallet.apple');
        [$certificate, $key] = static::credentials((string) $c['cert_path'], (string) ($c['cert_password'] ?? ''));

        $dir = rtrim(sys_get_temp_dir(), '/').'/daawa-pkpass-'.Str::random(12);
        File::ensureDirectoryExists($dir, 0700);

        try {
            file_put_contents("{$dir}/manifest.json", $manifest);
            $ok = openssl_pkcs7_sign("{$dir}/manifest.json", "{$dir}/signature", $certificate, $key, [], PKCS7_BINARY | PKCS7_DETACHED, (string) $c['wwdr_path']);
            if (! $ok) {
                throw new RuntimeException('Apple pass signing failed: '.openssl_error_string());
            }

            return static::smimeToDer((string) file_get_contents("{$dir}/signature"));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * The certificate and key from a .p12 (PKCS #12) or a PEM file holding both.
     *
     * @return array{0: string, 1: array{0: string, 1: string}|string}
     */
    public static function credentials(string $path, string $password): array
    {
        $contents = (string) file_get_contents($path);

        if (! str_contains($contents, '-----BEGIN')) {
            if (! openssl_pkcs12_read($contents, $certs, $password)) {
                throw new RuntimeException('Cannot read the Apple pass certificate (.p12): check APPLE_PASS_CERT_PASSWORD.');
            }

            return [$certs['cert'], $certs['pkey']];
        }

        return [$contents, [$contents, $password]];
    }

    /** openssl_pkcs7_sign writes S/MIME; Wallet wants the bare signature in DER. */
    public static function smimeToDer(string $smime): string
    {
        if (! preg_match('/filename="?smime\.p7s"?\s*\r?\n\r?\n(.+?)(?:\r?\n){1,2}-{2,}/s', $smime, $m)) {
            throw new RuntimeException('Unexpected S/MIME output while signing the pass.');
        }

        return (string) base64_decode(preg_replace('/\s+/', '', $m[1]), true);
    }

    /**
     * @param  array<string, string>  $files
     */
    public static function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pkpass');
        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($files as $name => $bytes) {
                $zip->addFromString($name, $bytes);
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
