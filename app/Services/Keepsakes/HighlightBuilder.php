<?php

namespace App\Services\Keepsakes;

use RuntimeException;

/**
 * Builds one highlight MP4 with ffmpeg (config keepsakes.video):
 *
 *   1. each part becomes its own short H.264 segment at the output size:
 *      a still (title card or photo) with a slow Ken Burns zoom (zoompan, the
 *      direction alternating), or a guest's clip cropped to fill the frame;
 *   2. the segments are joined with crossfades (xfade), and the chosen
 *      track (if any) fades in and out under them;
 *   3. the file is capped in time (-t), size (-fs and max_mb) and run time
 *      (every call shares the job's deadline).
 */
class HighlightBuilder
{
    public function __construct(private Ffmpeg $ffmpeg) {}

    /**
     * @param  list<array{type: 'still'|'clip', path: string, seconds: float, zoom?: float}>  $parts
     * @param  float  $deadline  unix time by which everything must be done
     * @return array{width: int, height: int, duration: float, bytes: int}
     */
    public function build(array $parts, int $width, int $height, ?string $music, string $out, string $workDir, float $deadline): array
    {
        if ($parts === []) {
            throw new RuntimeException('Nothing to put in the video.');
        }

        $c = config('keepsakes.video');
        $fps = (int) $c['fps'];
        $fade = (float) $c['fade_seconds'];
        $maxBytes = (int) $c['max_mb'] * 1024 * 1024;

        $segments = [];
        foreach (array_values($parts) as $i => $part) {
            $segment = "{$workDir}/seg-{$width}x{$height}-{$i}.mp4";
            $this->ffmpeg->run($this->segmentArgs($part, $i, $width, $height, $fps, $segment), $this->left($deadline), $workDir);
            $segments[] = ['path' => $segment, 'seconds' => (float) $part['seconds']];
        }

        $total = array_sum(array_column($segments, 'seconds')) - (count($segments) - 1) * $fade;

        $args = ['-y'];
        foreach ($segments as $segment) {
            array_push($args, '-i', $segment['path']);
        }
        if ($music) {
            array_push($args, '-stream_loop', '-1', '-i', $music);
        }

        $filters = [];
        $last = '0:v';
        $offset = 0.0;
        for ($i = 1; $i < count($segments); $i++) {
            $offset += $segments[$i - 1]['seconds'] - $fade;
            $label = "x{$i}";
            $filters[] = sprintf('[%s][%d:v]xfade=transition=fade:duration=%s:offset=%s[%s]', $last, $i, static::num($fade), static::num($offset), $label);
            $last = $label;
        }
        $filters[] = "[{$last}]format=yuv420p[vout]";
        if ($music) {
            $filters[] = sprintf('[%d:a]atrim=0:%s,afade=t=in:st=0:d=1,afade=t=out:st=%s:d=2,asetpts=PTS-STARTPTS[aout]', count($segments), static::num($total), static::num(max(0, $total - 2)));
        }

        array_push($args, '-filter_complex', implode(';', $filters), '-map', '[vout]');
        if ($music) {
            array_push($args, '-map', '[aout]', '-c:a', 'aac', '-b:a', (string) $c['audio_bitrate']);
        } else {
            $args[] = '-an';
        }
        array_push($args,
            '-c:v', 'libx264', '-preset', (string) $c['preset'], '-crf', (string) $c['crf'], '-pix_fmt', 'yuv420p', '-r', (string) $fps,
            '-movflags', '+faststart', '-t', static::num($total), '-fs', (string) $maxBytes, $out,
        );

        $this->ffmpeg->run($args, $this->left($deadline), $workDir);

        $bytes = is_file($out) ? (int) filesize($out) : 0;
        if ($bytes === 0) {
            throw new RuntimeException('ffmpeg wrote no video.');
        }
        if ($bytes >= $maxBytes) {
            throw new RuntimeException("The video is larger than {$c['max_mb']} MB.");
        }

        return $this->ffmpeg->probe($out) + ['bytes' => $bytes];
    }

    /**
     * A frame of the finished video as a JPEG (the poster on the album page).
     */
    public function poster(string $video, float $at, string $out, float $deadline): void
    {
        $this->ffmpeg->run(['-y', '-ss', static::num($at), '-i', $video, '-frames:v', '1', '-q:v', '3', $out], $this->left($deadline));
    }

    /**
     * @param  array{type: string, path: string, seconds: float, zoom?: float}  $part
     * @return list<string>
     */
    private function segmentArgs(array $part, int $index, int $width, int $height, int $fps, string $out): array
    {
        $c = config('keepsakes.video');
        $seconds = (float) $part['seconds'];
        $frames = max(1, (int) round($seconds * $fps));
        $encode = ['-c:v', 'libx264', '-preset', (string) $c['preset'], '-crf', (string) $c['crf'], '-pix_fmt', 'yuv420p', '-r', (string) $fps, '-an', '-frames:v', (string) $frames, $out];

        if ($part['type'] === 'clip') {
            $vf = sprintf('scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,fps=%d,tpad=stop_mode=clone:stop_duration=%s,setsar=1,format=yuv420p', $width, $height, $width, $height, $fps, static::num($seconds));

            return ['-y', '-t', static::num($seconds), '-i', $part['path'], '-vf', $vf, ...$encode];
        }

        // Ken Burns: zoom in on even parts, out on odd ones, drifting a little sideways;
        // the still is scaled up first so the zoom does not shimmer
        $zoom = max(1.0, (float) ($part['zoom'] ?? $c['zoom']));
        $big = [static::even($width * 1.5), static::even($height * 1.5)];
        $last = max(1, $frames - 1);
        $z = $index % 2 === 0
            ? sprintf('1+%s*on/%d', static::num($zoom - 1), $last)
            : sprintf('%s-%s*on/%d', static::num($zoom), static::num($zoom - 1), $last);
        $x = $index % 4 < 2 ? 'iw/2-(iw/zoom/2)' : sprintf('(iw-iw/zoom)*on/%d', $last);
        $vf = sprintf(
            "scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,zoompan=z='%s':x='%s':y='ih/2-(ih/zoom/2)':d=1:s=%dx%d:fps=%d,setsar=1,format=yuv420p",
            $big[0], $big[1], $big[0], $big[1], $z, $x, $width, $height, $fps,
        );

        return ['-y', '-loop', '1', '-framerate', (string) $fps, '-t', static::num($seconds), '-i', $part['path'], '-vf', $vf, ...$encode];
    }

    private function left(float $deadline): float
    {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            throw new RuntimeException('The highlight video took too long.');
        }

        return $left;
    }

    private static function even(float $value): int
    {
        return (int) (2 * round($value / 2));
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
