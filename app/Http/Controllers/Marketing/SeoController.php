<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Support\SiteUrl;
use Illuminate\Http\Response;

/**
 * sitemap.xml (public site pages only, never guest links) and robots.txt.
 */
class SeoController extends Controller
{
    /** Never crawled: guest links, previews, the app behind sign-in, internal tools. */
    public const DISALLOW = [
        '/i/', '/p/', '/e/', '/r/', '/dashboard', '/create', '/admin', '/account', '/orders', '/checkout',
        '/events/', '/households/', '/login', '/register', '/forgot-password', '/reset-password', '/locale/',
        '/auth/', '/pulse', '/horizon', '/webhooks/', '/broadcasting/', '/livewire', '/admin-preview/',
    ];

    /**
     * Link-preview fetchers that read og:title/og:image of a shared invitation. The
     * pages stay noindex (X-Robots-Tag and meta) for them too.
     */
    public const PREVIEW_BOTS = ['facebookexternalhit', 'Facebot', 'Twitterbot', 'TelegramBot', 'WhatsApp', 'Slackbot', 'Discordbot', 'LinkedInBot'];

    /**
     * @return list<string>
     */
    public static function sitemapPaths(): array
    {
        return array_merge(
            [['home', null], ['pricing', null], ['about', null]],
            array_map(fn ($slug) => ['occasion', $slug], array_keys(config('marketing.occasions'))),
            array_map(fn ($page) => ['legal', $page], SiteUrl::LEGAL),
        );
    }

    public function sitemap(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";

        foreach (static::sitemapPaths() as [$page, $param]) {
            $alternates = SiteUrl::alternates($page, $param);
            foreach (['ar', 'en'] as $locale) {
                $xml .= "  <url>\n    <loc>".e(SiteUrl::to($page, $locale, $param))."</loc>\n";
                foreach ($alternates as $lang => $href) {
                    $xml .= '    <xhtml:link rel="alternate" hreflang="'.$lang.'" href="'.e($href).'"/>'."\n";
                }
                $xml .= '    <changefreq>'.($page === 'legal' ? 'yearly' : 'weekly')."</changefreq>\n";
                $xml .= '    <priority>'.match ($page) {
                    'home' => '1.0',
                    'pricing', 'occasion' => '0.8',
                    'legal' => '0.3',
                    default => '0.5',
                }."</priority>\n  </url>\n";
            }
        }

        return response($xml.'</urlset>'."\n", 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function robots(): Response
    {
        $disallow = implode("\n", array_map(fn ($path) => "Disallow: {$path}", static::DISALLOW));
        $previewDisallow = implode("\n", array_map(fn ($path) => "Disallow: {$path}", array_diff(static::DISALLOW, ['/i/'])));
        $bots = implode("\n", array_map(fn ($bot) => "User-agent: {$bot}", static::PREVIEW_BOTS));
        $sitemap = rtrim((string) config('app.url'), '/').'/sitemap.xml';

        $body = <<<TXT
        # Daawa: public site pages only. Guest invitations are private links (noindex).
        User-agent: *
        {$disallow}

        # Link previews of a shared invitation (title and image only; the pages are noindex)
        {$bots}
        Allow: /i/
        {$previewDisallow}

        Sitemap: {$sitemap}

        TXT;

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
