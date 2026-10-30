<?php

namespace App\Services\Wallet;

use App\Models\Event;
use App\Models\Household;
use App\Models\WalletPass;
use Firebase\JWT\JWT;
use RuntimeException;

/**
 * "Save to Google Wallet" (plan section 10): an eventTicketClass per event and
 * an eventTicketObject per household, both inline in a JWT signed RS256 with
 * the issuer's service account key. Google creates (or updates) them when the
 * guest opens https://pay.google.com/gp/v/save/{jwt}: no REST call needed.
 * Pushing later changes (PATCH through the REST API) is a later step.
 */
class GooglePass
{
    public const SAVE_URL = 'https://pay.google.com/gp/v/save/';

    public function configured(): bool
    {
        $c = config('wallet.google');

        return filled($c['issuer_id'] ?? null) && is_file((string) ($c['service_account_json'] ?? ''));
    }

    public function saveUrl(Event $event, Household $household, PassData $data, WalletPass $pass): string
    {
        return self::SAVE_URL.$this->jwt($event, $household, $data, $pass);
    }

    public function jwt(Event $event, Household $household, PassData $data, WalletPass $pass): string
    {
        $account = $this->serviceAccount();

        return JWT::encode($this->claims($event, $household, $data, $pass, $account['client_email']), $account['private_key'], 'RS256');
    }

    public function claims(Event $event, Household $household, PassData $data, WalletPass $pass, string $issuerEmail): array
    {
        return [
            'iss' => $issuerEmail,
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'origins' => array_values(array_unique([rtrim((string) config('app.url'), '/'), rtrim((string) config('app.invite_url'), '/')])),
            'payload' => [
                'eventTicketClasses' => [$this->ticketClass($event, $data)],
                'eventTicketObjects' => [$this->ticketObject($event, $household, $data, $pass)],
            ],
        ];
    }

    public function classId(Event $event): string
    {
        return config('wallet.google.issuer_id').'.daawa-event-'.$event->getKey();
    }

    public function ticketClass(Event $event, PassData $data): array
    {
        $lang = $data->locale;
        $text = fn (?string $value) => ['defaultValue' => ['language' => $lang, 'value' => (string) $value]];

        $class = [
            'id' => $this->classId($event),
            'issuerName' => config('wallet.google.issuer_name'),
            'reviewStatus' => 'UNDER_REVIEW',
            'eventName' => $text($data->eventName),
            'hexBackgroundColor' => $data->colors['background'],
            'heroImage' => [
                'sourceUri' => ['uri' => rtrim((string) config('app.invite_url'), '/').route('invite.wallet.strip', $event->invitation->slug, false)],
                'contentDescription' => $text($data->couple),
            ],
        ];

        if ($data->hall) {
            $class['venue'] = ['name' => $text($data->hall), 'address' => $text($data->address ?: $data->hall)];
        }
        if ($data->start) {
            $class['dateTime'] = ['start' => $data->start];
        }
        if ($data->lat !== null && $data->lng !== null) {
            $class['locations'] = [['latitude' => $data->lat, 'longitude' => $data->lng]];
        }

        return $class;
    }

    public function ticketObject(Event $event, Household $household, PassData $data, WalletPass $pass): array
    {
        $t = fn (string $key) => trans("wallet.pass.{$key}", [], $data->locale);
        $modules = array_values(array_filter([
            ['id' => 'seats', 'header' => $t('seats'), 'body' => $data->seatsLabel()],
            $data->time ? ['id' => 'time', 'header' => $t('time'), 'body' => $data->time] : null,
        ]));

        $object = [
            'id' => config('wallet.google.issuer_id').'.'.$pass->serial,
            'classId' => $this->classId($event),
            'state' => 'ACTIVE',
            'ticketHolderName' => $data->guest,
            'barcode' => ['type' => 'QR_CODE', 'value' => $data->url, 'alternateText' => $t('show_at_door')],
            'textModulesData' => $modules,
            'hexBackgroundColor' => $data->colors['background'],
        ];

        if ($data->table) {
            $object['seatInfo'] = ['section' => ['defaultValue' => ['language' => $data->locale, 'value' => $t('table').' '.$data->table]]];
        }

        return $object;
    }

    /**
     * @return array{client_email: string, private_key: string}
     */
    private function serviceAccount(): array
    {
        $json = json_decode((string) file_get_contents((string) config('wallet.google.service_account_json')), true);
        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new RuntimeException('GOOGLE_WALLET_SERVICE_ACCOUNT_JSON is not a service account key file.');
        }

        return ['client_email' => $json['client_email'], 'private_key' => $json['private_key']];
    }
}
