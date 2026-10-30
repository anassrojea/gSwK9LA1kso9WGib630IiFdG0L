<?php

namespace App\Services\Pdf;

use App\Services\OgImage\Chromium;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Printable PDFs (seating chart, table cards, QR sheets) from Blade views,
 * printed by the same headless Chromium as the link preview images
 * (config/og.php), so Arabic is shaped by a real browser. The page size comes
 * from the view's own @page rule. Every network request is blocked: the HTML
 * must be self-contained (fonts inlined by FontEmbedder, QR codes as SVG).
 */
class PdfRenderer
{
    public function __construct(private Chromium $chromium) {}

    public function available(): bool
    {
        return $this->chromium->available();
    }

    /**
     * @throws RuntimeException when Chromium is missing, fails or times out
     */
    public function render(string $html, ?int $timeout = null): string
    {
        $binary = $this->chromium->binary() ?? throw new RuntimeException('Chromium not found: set OG_CHROME_PATH.');

        $dir = rtrim(sys_get_temp_dir(), '/').'/daawa-pdf-'.Str::random(16);
        File::ensureDirectoryExists($dir, 0700);

        try {
            file_put_contents("{$dir}/page.html", $html);
            $out = "{$dir}/out.pdf";

            $process = new Process([
                $binary,
                '--headless',
                ...(config('og.chrome_no_sandbox') ? ['--no-sandbox'] : []),
                '--disable-gpu',
                '--disable-dev-shm-usage',
                '--disable-extensions',
                '--disable-background-networking',
                '--disable-sync',
                '--no-first-run',
                '--no-default-browser-check',
                '--mute-audio',
                '--no-proxy-server',
                '--host-resolver-rules=MAP * ~NOTFOUND',
                '--no-pdf-header-footer',
                '--virtual-time-budget=5000',
                "--print-to-pdf={$out}",
                'file://'."{$dir}/page.html",
            ],
                $dir,
                ['HOME' => $dir, 'XDG_CONFIG_HOME' => "{$dir}/.config", 'XDG_CACHE_HOME' => "{$dir}/.cache"],
            );
            $process->setTimeout($timeout ?? max(30, (int) config('og.timeout', 30) * 2));
            $process->run();

            $pdf = is_file($out) ? file_get_contents($out) : false;

            if (! $process->isSuccessful() || $pdf === false || ! str_starts_with($pdf, '%PDF')) {
                throw new RuntimeException('Chromium PDF failed (exit '.$process->getExitCode().'): '.Str::limit(trim($process->getErrorOutput()), 500));
            }

            return $pdf;
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
