<?php

namespace App\Services\Album;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * An inline SVG QR code (the wall's "upload your photos" code, the web pass).
 * Light modules are left transparent: put it on a light background.
 */
class QrSvg
{
    public static function make(string $data, string $dark = '#000000'): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'drawLightModules' => false,
            'svgAddXmlHeader' => false,
            'connectPaths' => true,
        ]);

        $svg = (new QRCode($options))->render($data);

        return preg_match('/^#[0-9a-fA-F]{3,6}$/', $dark) ? str_replace('fill="#000"', 'fill="'.$dark.'"', $svg) : $svg;
    }
}
