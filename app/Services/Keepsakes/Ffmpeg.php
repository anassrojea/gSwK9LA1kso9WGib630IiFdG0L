<?php

namespace App\Services\Keepsakes;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The ffmpeg binary (config keepsakes.video.ffmpeg, FFMPEG_PATH) run through
 * symfony/process with a time limit. Every call gets an explicit argument
 * list: no shell, so file names never need quoting.
 */
class Ffmpeg
{
    public function binary(): ?string
    {
        $path = (string) config('keepsakes.video.ffmpeg', '');
        if ($path === '') {
            $path = (string) (new Process(['sh', '-c', 'command -v ffmpeg']))->mustRun()->getOutput();
            $path = trim($path);
        }

        return $path !== '' && is_file($path) && is_executable($path) ? $path : null;
    }

    public function available(): bool
    {
        try {
            return $this->binary() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $args  without the binary
     *
     * @throws RuntimeException when ffmpeg is missing, fails or runs out of time
     */
    public function run(array $args, float $timeout, ?string $cwd = null): string
    {
        $binary = $this->binary() ?? throw new RuntimeException('ffmpeg not found: set FFMPEG_PATH.');

        $process = new Process([$binary, '-hide_banner', '-nostdin', '-loglevel', 'error', ...$args], $cwd);
        $process->setTimeout(max(1.0, $timeout));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('ffmpeg failed (exit '.$process->getExitCode().'): '.mb_substr(trim($process->getErrorOutput()), -800));
        }

        return $process->getErrorOutput();
    }

    /**
     * Width, height and duration (seconds) of a media file, read from ffmpeg's own banner.
     *
     * @return array{width: int, height: int, duration: float}
     */
    public function probe(string $file, float $timeout = 30): array
    {
        $binary = $this->binary() ?? throw new RuntimeException('ffmpeg not found: set FFMPEG_PATH.');

        // "ffmpeg -i file" prints the stream info and exits 1 (no output named): that is expected
        $process = new Process([$binary, '-hide_banner', '-nostdin', '-i', $file]);
        $process->setTimeout($timeout);
        $process->run();
        $info = $process->getErrorOutput();

        preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $info, $d);
        preg_match('/Video:.*?(\d{2,5})x(\d{2,5})/', $info, $v);
        if (! $d || ! $v) {
            throw new RuntimeException('Cannot read media info: '.mb_substr(trim($info), -400));
        }

        return [
            'width' => (int) $v[1],
            'height' => (int) $v[2],
            'duration' => (int) $d[1] * 3600 + (int) $d[2] * 60 + (float) $d[3],
        ];
    }
}
