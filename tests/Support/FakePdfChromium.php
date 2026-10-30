<?php

namespace Tests\Support;

use App\Services\OgImage\Chromium;

/**
 * Stands in for headless Chromium when printing invoices: keeps the HTML it
 * was given and returns a tiny PDF-looking body.
 */
class FakePdfChromium extends Chromium
{
    public int $renders = 0;

    /** @var list<string> */
    public array $html = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(Chromium::class, $fake);

        return $fake;
    }

    public function available(): bool
    {
        return true;
    }

    public function pdf(string $html, int $timeout): string
    {
        $this->renders++;
        $this->html[] = $html;

        return "%PDF-1.4\n% fake invoice\n".strlen($html)."\n%%EOF";
    }
}
