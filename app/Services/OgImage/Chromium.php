<?php

namespace App\Services\OgImage;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Screenshots (or prints to PDF) an HTML document with headless Chromium's own CLI
 * (--screenshot, --print-to-pdf), run through symfony/process. No Node, Puppeteer or
 * Browsershot needed: only a Chromium binary (OG_CHROME_PATH). Every network
 * request is blocked, so the HTML must be self-contained (fonts inlined as
 * data: URIs, see FontEmbedder).
 */
class Chromium
{
    /** Looked up in order when OG_CHROME_PATH is empty. */
    public const CANDIDATES = [
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/opt/pw-browsers/chromium',
    ];

    public function binary(): ?string
    {
        $configured = config('og.chrome_path');
        if ($configured) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        foreach (self::CANDIDATES as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    public function available(): bool
    {
        return $this->binary() !== null;
    }

    /**
     * Render $html at width × height CSS pixels (device scale 1) and return the PNG bytes.
     *
     * @throws RuntimeException when Chromium is missing, fails or times out
     */
    public function screenshot(string $html, int $width, int $height, int $timeout): string
    {
        return $this->run($html, 'shot.png', [
            '--hide-scrollbars',
            '--force-device-scale-factor=1',
            "--window-size={$width},{$height}",
        ], fn (string $out) => "--screenshot={$out}", "\x89PNG\r\n\x1a\n", $timeout);
    }

    /**
     * Print $html to PDF (page size and margins from the document's own @page rule)
     * and return the PDF bytes. Used for invoices (App\Services\Invoicing\InvoicePdf):
     * a real browser shapes Arabic correctly, which PHP PDF libraries often don't.
     *
     * @throws RuntimeException when Chromium is missing, fails or times out
     */
    public function pdf(string $html, int $timeout): string
    {
        return $this->run($html, 'page.pdf', [
            '--no-pdf-header-footer',
            '--print-to-pdf-no-header',
        ], fn (string $out) => "--print-to-pdf={$out}", '%PDF-', $timeout);
    }

    /**
     * @param  list<string>  $args
     * @param  callable(string): string  $output  the flag that writes to the given file
     */
    private function run(string $html, string $file, array $args, callable $output, string $magic, int $timeout): string
    {
        $binary = $this->binary() ?? throw new RuntimeException('Chromium not found: set OG_CHROME_PATH.');

        $dir = rtrim(sys_get_temp_dir(), '/').'/daawa-og-'.Str::random(16);
        File::ensureDirectoryExists($dir, 0700);

        try {
            file_put_contents("{$dir}/page.html", $html);
            $out = "{$dir}/{$file}";

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
                ...$args,
                // nothing leaves the machine: fonts and images are inlined
                '--no-proxy-server',
                '--host-resolver-rules=MAP * ~NOTFOUND',
                // lets inlined fonts decode before the shot is taken
                '--virtual-time-budget=5000',
                $output($out),
                'file://'."{$dir}/page.html",
            ],
                $dir,
                // A throwaway profile per run, so parallel renders never share one. Set through
                // HOME rather than --user-data-dir, which makes some headless builds hang.
                ['HOME' => $dir, 'XDG_CONFIG_HOME' => "{$dir}/.config", 'XDG_CACHE_HOME' => "{$dir}/.cache"],
            );
            $process->setTimeout($timeout);
            $process->run();

            $bytes = is_file($out) ? file_get_contents($out) : false;

            if (! $process->isSuccessful() || $bytes === false || ! str_starts_with($bytes, $magic)) {
                throw new RuntimeException('Chromium render failed (exit '.$process->getExitCode().'): '.Str::limit(trim($process->getErrorOutput()), 500));
            }

            return $bytes;
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
