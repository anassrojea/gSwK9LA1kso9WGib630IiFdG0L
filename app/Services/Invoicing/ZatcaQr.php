<?php

namespace App\Services\Invoicing;

use App\Services\Payments\Currencies;
use Carbon\CarbonInterface;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use InvalidArgumentException;

/**
 * ZATCA phase-1 ("generation phase") QR code for simplified tax invoices:
 * five TLV fields, base64-encoded.
 *
 *   1 seller name   2 VAT registration number   3 timestamp (ISO 8601, UTC)
 *   4 invoice total with VAT   5 VAT total
 *
 * Each field is one tag byte, one length byte (the value's length in UTF-8
 * bytes, so at most 255) and the value. Phase 2 (signed XML, cryptographic
 * stamp, clearance/reporting API) is not implemented: see docs/INVOICES.md.
 */
class ZatcaQr
{
    /**
     * @param  array<int, string>  $fields  tag => value
     */
    public static function tlv(array $fields): string
    {
        $bytes = '';
        foreach ($fields as $tag => $value) {
            $length = strlen($value);
            if ($tag < 1 || $tag > 255 || $length > 255) {
                throw new InvalidArgumentException("TLV field {$tag} is out of range ({$length} bytes).");
            }
            $bytes .= chr($tag).chr($length).$value;
        }

        return $bytes;
    }

    public static function payload(string $seller, string $vatNumber, CarbonInterface $issuedAt, string $total, string $vat): string
    {
        return base64_encode(static::tlv([
            1 => $seller,
            2 => $vatNumber,
            3 => $issuedAt->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            4 => $total,
            5 => $vat,
        ]));
    }

    /**
     * Minor units to the plain decimal string the QR carries: 11500 SAR → "115.00".
     */
    public static function amount(int $minor, string $currency): string
    {
        $exponent = Currencies::exponent($currency);
        $minor = abs($minor);

        return $exponent === 0
            ? (string) $minor
            : intdiv($minor, 10 ** $exponent).'.'.str_pad((string) ($minor % (10 ** $exponent)), $exponent, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<int, string> tag => value
     */
    public static function decode(string $base64): array
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new InvalidArgumentException('Not base64.');
        }

        $fields = [];
        for ($i = 0; $i < strlen($bytes);) {
            $tag = ord($bytes[$i]);
            $length = ord($bytes[$i + 1] ?? "\0");
            $fields[$tag] = substr($bytes, $i + 2, $length);
            $i += 2 + $length;
        }

        return $fields;
    }

    /**
     * The QR as an SVG data: URI for the PDF.
     */
    public static function svgDataUri(string $payload): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'svgAddXmlHeader' => false,
            'drawLightModules' => false,
        ]);

        return (new QRCode($options))->render($payload);
    }
}
