<?php

namespace App\Services\OgImage;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Screenshots an HTML document with headless Chromium's own CLI
 * (--screenshot), run through symfony/process. No Node, Puppeteer or
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
        $binary = $this->binary() ?? throw new RuntimeException('Chromium not found: set OG_CHROME_PATH.');

        $dir = rtrim(sys_get_temp_dir(), '/').'/daawa-og-'.Str::random(16);
        File::ensureDirectoryExists($dir, 0700);

        try {
            file_put_contents("{$dir}/page.html", $html);
            $out = "{$dir}/shot.png";

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
                '--hide-scrollbars',
                '--force-device-scale-factor=1',
                // nothing leaves the machine: fonts and images are inlined
                '--no-proxy-server',
                '--host-resolver-rules=MAP * ~NOTFOUND',
                "--window-size={$width},{$height}",
                // lets inlined fonts decode before the shot is taken
                '--virtual-time-budget=5000',
                "--screenshot={$out}",
                'file://'."{$dir}/page.html",
            ],
                $dir,
                // A throwaway profile per run, so parallel renders never share one. Set through
                // HOME rather than --user-data-dir, which makes some headless builds hang.
                ['HOME' => $dir, 'XDG_CONFIG_HOME' => "{$dir}/.config", 'XDG_CACHE_HOME' => "{$dir}/.cache"],
            );
            $process->setTimeout($timeout);
            $process->run();

            $png = is_file($out) ? file_get_contents($out) : false;

            if (! $process->isSuccessful() || $png === false || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
                throw new RuntimeException('Chromium screenshot failed (exit '.$process->getExitCode().'): '.Str::limit(trim($process->getErrorOutput()), 500));
            }

            return $png;
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
