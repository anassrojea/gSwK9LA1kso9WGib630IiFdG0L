<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers (config/security.php) on the public site and the Inertia
 * pages ("app" profile, in the web group) and on guest invitations ("invite",
 * in the invite group, next to InviteResponseHeaders). A header a response
 * already set is kept.
 *
 *  - Content-Security-Policy, report-only until CSP_ENFORCE=true: own scripts
 *    only (no inline JS), Google Fonts, the media disk, PostHog and Sentry
 *    hosts as configured, the Reverb WebSocket; framing by our own pages only
 *    (the wizard's live preview is a same-origin iframe).
 *  - X-Frame-Options: SAMEORIGIN, Permissions-Policy, nosniff, Referrer-Policy.
 *  - Strict-Transport-Security in production.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next, string $profile = 'app'): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        if (config('security.csp.enabled')) {
            $name = config('security.csp.enforce') ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
            if (! $headers->has('Content-Security-Policy') && ! $headers->has('Content-Security-Policy-Report-Only')) {
                $headers->set($name, static::policy($profile));
            }
        }

        $defaults = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => (string) config('security.permissions_policy'),
            'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
        ];
        foreach ($defaults as $header => $value) {
            if (! $headers->has($header) && $value !== '') {
                $headers->set($header, $value);
            }
        }

        if (app()->isProduction() && $request->isSecure() && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age='.(int) config('security.hsts.max_age')
                .(config('security.hsts.include_subdomains') ? '; includeSubDomains' : ''));
        }

        return $response;
    }

    public static function policy(string $profile = 'app'): string
    {
        $media = static::mediaHosts();
        $connect = array_merge(["'self'"], static::reverbHosts(), static::sentryHosts(), config('security.csp.connect_hosts', []));
        $scripts = ["'self'"];
        $styles = ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'];

        if ($profile === 'app' && ($posthog = config('security.csp.posthog_host'))) {
            $connect[] = static::origin($posthog);
        }

        // npm run dev: scripts, styles and HMR come from the Vite server
        if (Vite::isRunningHot()) {
            $hot = static::origin(trim((string) @file_get_contents(public_path('hot'))));
            if ($hot) {
                $scripts[] = $hot;
                $styles[] = $hot;
                $connect[] = $hot;
                $connect[] = preg_replace('#^http#', 'ws', $hot);
            }
        }

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => $scripts,
            'style-src' => $styles,
            'font-src' => ["'self'", 'https://fonts.gstatic.com', 'data:'],
            'img-src' => array_merge(["'self'", 'data:', 'blob:'], $media),
            'media-src' => array_merge(["'self'", 'blob:'], $media),
            'connect-src' => $connect,
            'frame-src' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
        ];

        if ($report = static::reportUri()) {
            $directives['report-uri'] = [$report];
        }

        return collect($directives)
            ->map(fn (array $sources, string $name) => $name.' '.implode(' ', array_values(array_unique(array_filter($sources)))))
            ->implode('; ');
    }

    /**
     * @return list<string>
     */
    private static function mediaHosts(): array
    {
        $disk = (string) config('filesystems.media_disk', 'public');
        $hosts = [
            static::origin((string) config("filesystems.disks.{$disk}.url")),
            static::origin((string) config("filesystems.disks.{$disk}.endpoint")),
            static::origin((string) config('app.url')),
            static::origin((string) config('app.invite_url')),
        ];

        return array_values(array_filter(array_merge($hosts, config('security.csp.media_hosts', []))));
    }

    /**
     * @return list<string>
     */
    private static function reverbHosts(): array
    {
        $host = config('broadcasting.connections.reverb.options.host');
        if (! $host) {
            return [];
        }
        $scheme = config('broadcasting.connections.reverb.options.scheme', 'https') === 'https' ? 'wss' : 'ws';
        $port = config('broadcasting.connections.reverb.options.port');
        $default = $scheme === 'wss' ? 443 : 80;

        return [$scheme.'://'.$host.($port && (int) $port !== $default ? ':'.$port : '')];
    }

    /**
     * @return list<string>
     */
    private static function sentryHosts(): array
    {
        $dsn = static::parseDsn();

        return $dsn ? [$dsn['origin']] : [];
    }

    private static function reportUri(): ?string
    {
        if ($uri = config('security.csp.report_uri')) {
            return $uri;
        }

        $dsn = static::parseDsn();

        return $dsn ? "{$dsn['origin']}/api/{$dsn['project']}/security/?sentry_key={$dsn['key']}" : null;
    }

    /**
     * @return array{origin: string, project: string, key: string}|null
     */
    private static function parseDsn(): ?array
    {
        $dsn = (string) config('security.csp.sentry_dsn');
        $parts = $dsn ? parse_url($dsn) : false;
        if (! $parts || empty($parts['host']) || empty($parts['user']) || empty($parts['path'])) {
            return null;
        }

        return [
            'origin' => ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''),
            'project' => trim($parts['path'], '/'),
            'key' => $parts['user'],
        ];
    }

    private static function origin(?string $url): ?string
    {
        $parts = $url ? parse_url($url) : false;
        if (! $parts || empty($parts['host'])) {
            return null;
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
