<?php

namespace App\Services\Qr;

use App\Models\Household;
use App\Services\Dashboard\WhatsApp;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * QR codes (plan section 9): every household's code is its personal link
 * /i/{token}, so one code works at the door, on paper and for table lookup.
 * Error correction H (30% may be covered) leaves room for a logo or initials
 * in the middle and survives a crumpled printout.
 */
class QrCodes
{
    public const ECC = 'H';

    public function url(Household $household): string
    {
        return WhatsApp::link($household);
    }

    /**
     * A compact, scalable SVG (one path for the dark modules).
     */
    public function svg(string $data, string $dark = '#111111', ?string $light = '#ffffff'): string
    {
        $options = new QROptions([
            'eccLevel' => EccLevel::H,
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'svgAddXmlHeader' => true,
            'drawLightModules' => false,
            'connectPaths' => true,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'cssClass' => 'qr',
            'bgColor' => $light,
            'moduleValues' => $this->moduleColors($dark),
        ]);

        return (new QRCode($options))->render($data);
    }

    /**
     * The same code as a PNG (GD), e.g. to decode it again in tests.
     */
    public function png(string $data, int $scale = 6): string
    {
        $options = new QROptions([
            'eccLevel' => EccLevel::H,
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => false,
            'scale' => $scale,
        ]);

        return (new QRCode($options))->render($data);
    }

    public function forHousehold(Household $household): string
    {
        return $this->svg($this->url($household));
    }

    /**
     * Every dark module type in one colour (finder, alignment, data …).
     *
     * @return array<int, string>
     */
    private function moduleColors(string $dark): array
    {
        $values = [];
        foreach ((new \ReflectionClass(QRMatrix::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'M_') && str_ends_with($name, '_DARK') && is_int($value)) {
                $values[$value] = $dark;
            }
        }

        return $values;
    }
}
