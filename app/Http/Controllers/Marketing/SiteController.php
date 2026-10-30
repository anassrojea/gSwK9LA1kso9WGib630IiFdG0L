<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Account\AccountDeletion;
use App\Services\Digits;
use App\Services\Payments\Currencies;
use App\Services\Site\SiteCatalog;
use App\Support\SiteUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The public site (plan section 15.1: Blade + Tailwind, SEO first): landing,
 * one page per occasion, pricing, about/contact and the legal pages, in
 * Arabic (no prefix) and English (/en). Routes: routes/marketing.php.
 */
class SiteController extends Controller
{
    public function __construct(private SiteCatalog $catalog) {}

    public function home(): View
    {
        $locale = App::getLocale();

        return view('marketing.home', [
            'seo' => $this->seo('home', null, __('marketing.meta.home_title'), __('marketing.meta.home_description')),
            'openings' => $this->catalog->openings(),
            'samples' => $this->catalog->samples($locale),
            'occasions' => config('marketing.occasions'),
        ]);
    }

    public function occasion(string $occasion): View
    {
        $value = config('marketing.occasions')[$occasion] ?? abort(404);
        $name = __("marketing.occasions.items.{$value}.name");

        return view('marketing.occasion', [
            'seo' => $this->seo('occasion', $occasion, __('marketing.meta.occasion_title', ['occasion' => __("marketing.occasions.items.{$value}.title")]), __("marketing.occasions.items.{$value}.intro")),
            'slug' => $occasion,
            'occasion' => $value,
            'name' => $name,
            'templates' => $this->catalog->templates($value),
            'occasions' => config('marketing.occasions'),
        ]);
    }

    public function pricing(Request $request): View
    {
        $locale = App::getLocale();
        $currency = $this->currency($request);

        return view('marketing.pricing', [
            'seo' => $this->seo('pricing', null, __('marketing.meta.pricing_title'), __('marketing.meta.pricing_description')),
            'currency' => $currency,
            'currencies' => Currencies::all(),
            'plans' => $this->catalog->plans($currency, $locale),
            'addons' => $this->catalog->addons($currency, $locale),
        ]);
    }

    public function about(): View
    {
        return view('marketing.about', [
            'seo' => $this->seo('about', null, __('marketing.meta.about_title'), __('marketing.meta.about_description')),
            'whatsapp' => static::whatsappUrl(__('marketing.about.whatsapp_message')),
            'email' => config('marketing.contact_email'),
        ]);
    }

    public function legal(string $page): View
    {
        abort_unless(in_array($page, SiteUrl::LEGAL, true), 404);

        $locale = App::getLocale();
        $file = resource_path("content/legal/{$locale}/{$page}.md");
        abort_unless(is_file($file), 404);

        return view('marketing.legal', [
            'seo' => $this->seo('legal', $page, __("legal.pages.{$page}.title").' · '.__('marketing.brand'), __("legal.pages.{$page}.description")),
            'page' => $page,
            'html' => $this->renderLegal((string) file_get_contents($file), $locale),
            'updated' => Digits::localize(CarbonImmutable::parse((string) config('marketing.legal_updated'))->locale($locale)->translatedFormat('j F Y'), $locale),
        ]);
    }

    /**
     * Markdown with :placeholders (URLs, contact, retention) to HTML. The draft
     * note at the top of each file is an HTML comment and stays in the source.
     */
    private function renderLegal(string $markdown, string $locale): string
    {
        $email = config('marketing.contact_email');
        $replace = [
            ':privacy_url' => SiteUrl::path('legal', $locale, 'privacy'),
            ':terms_url' => SiteUrl::path('legal', $locale, 'terms'),
            ':refund_url' => SiteUrl::path('legal', $locale, 'refund'),
            ':cookies_url' => SiteUrl::path('legal', $locale, 'cookies'),
            ':pricing_url' => SiteUrl::path('pricing', $locale),
            ':account_privacy_url' => '/account/privacy',
            ':whatsapp_url' => static::whatsappUrl() ?? SiteUrl::path('about', $locale),
            ':contact_email_line' => $email ? __('legal.contact_email', ['email' => $email]) : '',
            ':grace_days' => Digits::localize(AccountDeletion::graceDays(), $locale),
            ':retention_list' => $this->catalog->retentionText($locale),
            ':consent_button' => '<p><button type="button" class="consent-reset" data-consent-reset>'.e(__('legal.consent_button')).'</button></p>',
        ];

        return Str::markdown(strtr($markdown, $replace), ['html_input' => 'allow', 'allow_unsafe_links' => false]);
    }

    /**
     * ?currency= wins, then the visitor's country (Cloudflare's CF-IPCountry), then the page language.
     */
    private function currency(Request $request): string
    {
        $asked = strtoupper((string) $request->query('currency'));
        if (Currencies::supported($asked)) {
            return $asked;
        }

        if ($country = $request->header('CF-IPCountry')) {
            return Currencies::forCountry($country);
        }

        return App::getLocale() === 'ar' && Currencies::supported('SAR') ? 'SAR' : Currencies::fallback();
    }

    /**
     * Title, description, canonical URL and hreflang alternates of a page.
     *
     * @return array{title: string, description: string, canonical: string, alternates: array<string, string>, page: string, param: ?string, image: string}
     */
    private function seo(string $page, ?string $param, string $title, string $description): array
    {
        return [
            'title' => $title,
            'description' => Str::limit($description, 300),
            'canonical' => SiteUrl::to($page, App::getLocale(), $param),
            'alternates' => SiteUrl::alternates($page, $param),
            'page' => $page,
            'param' => $param,
            'image' => asset('images/og-site-'.App::getLocale().'.png'),
        ];
    }

    public static function whatsappUrl(?string $text = null): ?string
    {
        $number = preg_replace('/\D+/', '', (string) config('payments.whatsapp_number'));

        return $number ? "https://wa.me/{$number}".($text ? '?text='.rawurlencode($text) : '') : null;
    }
}
