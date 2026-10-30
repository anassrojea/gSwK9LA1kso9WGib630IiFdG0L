<?php

namespace App\Http\Controllers\Marketplace;

use App\Filament\Resources\Themes\ThemeResource;
use App\Http\Controllers\Controller;
use App\Models\DesignerLedgerEntry;
use App\Models\DesignerPayout;
use App\Models\DesignerProfile;
use App\Models\Event;
use App\Models\MarketplaceSignatureUse;
use App\Models\Opening;
use App\Models\Template;
use App\Models\TemplateRevision;
use App\Models\Theme;
use App\Services\Catalog\CatalogValidator;
use App\Services\Marketplace\DesignerLedger;
use App\Services\Marketplace\Designers;
use App\Services\Marketplace\MarketplacePrices;
use App\Services\Marketplace\MarketplaceUrl;
use App\Services\Marketplace\TemplateStudio;
use App\Services\Payments\Currencies;
use App\Services\Wizard\DraftCreator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The designer portal (/designer, plan section 2): the application while pending, then
 * themes, templates with their review state, sales per template and currency, the balance
 * owed and the monthly statements. A designer only ever sees their own rows.
 */
class DesignerPortalController extends Controller
{
    public function __construct(private TemplateStudio $studio) {}

    /**
     * GET /designer
     */
    public function index(Request $request, DesignerLedger $ledger): Response
    {
        $user = $request->user();
        $profile = $user->designerProfile()->first();
        $locale = App::getLocale();

        if (! $profile?->isApproved()) {
            return Inertia::render('designer/Apply', [
                'profile' => $profile ? [
                    'status' => $profile->status,
                    'status_reason' => $profile->status_reason,
                    'display_name_ar' => $profile->display_name_ar,
                    'display_name_en' => $profile->display_name_en,
                    'bio_ar' => $profile->bio_ar,
                    'bio_en' => $profile->bio_en,
                    'portfolio_links' => $profile->portfolio_links ?? [],
                    'country' => $profile->country,
                    'has_payout_details' => filled($profile->payout_details),
                ] : null,
                'terms' => $this->termsHtml($locale),
                'countries' => array_keys(config('payments.countries')),
            ]);
        }

        $templates = Template::query()->where('designer_id', $user->getKey())->with(['revisions'])->orderByDesc('updated_at')->get();
        $sales = DesignerLedgerEntry::query()
            ->where('designer_id', $user->getKey())
            ->where('account', 'designer_payable')
            ->whereIn('kind', ['sale', 'refund'])
            ->groupBy('template_id', 'currency', 'kind')
            ->selectRaw('template_id, currency, kind, count(*) as n, sum(credit_cents) - sum(debit_cents) as amount')
            ->get();

        return Inertia::render('designer/Index', [
            'profile' => [
                'name' => $profile->displayName($locale),
                'slug' => $profile->slug,
                'share_bp' => $profile->shareBp(),
                'public_url' => MarketplaceUrl::path('designer', $locale, $profile->slug),
            ],
            'templates' => $templates->map(function (Template $t) use ($sales, $locale) {
                $latest = $t->revisions->first();
                $mine = $sales->where('template_id', $t->id);

                return [
                    'id' => $t->id,
                    'name' => $locale === 'ar' ? ($latest?->name_ar ?? $t->name_ar) : ($latest?->name_en ?? $t->name_en),
                    'status' => $t->marketplace_status,
                    'revision' => $latest ? ['number' => $latest->number, 'status' => $latest->status, 'rejection_reason' => $latest->rejection_reason] : null,
                    'live_revision' => $t->revisions->firstWhere('id', $t->live_revision_id)?->number,
                    'cover' => $this->coverUrl($t),
                    'prices' => MarketplacePrices::formatted($t->prices, $locale),
                    'included_in_signature' => $t->included_in_signature,
                    'sales' => (int) $mine->where('kind', 'sale')->sum('n') - (int) $mine->where('kind', 'refund')->sum('n'),
                    'earned' => $mine->groupBy('currency')->map(fn ($rows, $c) => Currencies::format((int) $rows->sum('amount'), $c, $locale))->all(),
                    'edit_url' => route('designer.templates.edit', $t, false),
                    'theme' => $t->default_config['theme'] ?? null,
                ];
            })->values(),
            'themes' => Theme::query()->where('designer_id', $user->getKey())->orderBy('name_en')->get()->map(fn (Theme $th) => [
                'id' => $th->id,
                'name' => $locale === 'ar' ? $th->name_ar : $th->name_en,
                'config' => $th->toConfig(),
                'edit_url' => route('designer.themes.edit', $th, false),
                'templates' => $th->templates()->count(),
            ])->values(),
            'earnings' => collect($ledger->earned($user->getKey()))->map(fn ($amount, $c) => ['currency' => $c, 'amount' => $amount, 'formatted' => Currencies::format($amount, $c, $locale)])->values(),
            'balances' => collect($ledger->balances($user->getKey()))->map(fn ($amount, $c) => [
                'currency' => $c,
                'amount' => $amount,
                'formatted' => Currencies::format($amount, $c, $locale),
                'minimum' => Currencies::format((int) config("marketplace.payout_minimum.{$c}", 0), $c, $locale),
            ])->values(),
            'payouts' => DesignerPayout::query()->where('designer_id', $user->getKey())->orderByDesc('period')->orderBy('currency')->limit(36)->get()->map(fn (DesignerPayout $p) => [
                'id' => $p->id,
                'period' => $p->period,
                'currency' => $p->currency,
                'amount' => Currencies::format($p->amount_cents, $p->currency, $locale),
                'sales_count' => $p->sales_count,
                'status' => $p->status,
                'reference' => $p->reference,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])->values(),
            'signature_uses' => MarketplaceSignatureUse::query()->where('designer_id', $user->getKey())->count(),
        ]);
    }

    /**
     * POST /designer/apply
     */
    public function apply(Request $request, Designers $designers): RedirectResponse
    {
        $data = $request->validate([
            'display_name_ar' => ['required', 'string', 'max:80'],
            'display_name_en' => ['required', 'string', 'max:80'],
            'bio_ar' => ['nullable', 'string', 'max:2000'],
            'bio_en' => ['nullable', 'string', 'max:2000'],
            'portfolio_links' => ['array', 'max:5'],
            'portfolio_links.*' => ['nullable', 'url:https,http', 'max:255'],
            'payout_details' => ['nullable', 'array'],
            'payout_details.holder' => ['nullable', 'string', 'max:120'],
            'payout_details.bank' => ['nullable', 'string', 'max:120'],
            'payout_details.iban' => ['nullable', 'string', 'max:64'],
            'payout_details.note' => ['nullable', 'string', 'max:500'],
            'country' => ['nullable', 'string', 'size:2'],
            'accept_terms' => ['accepted'],
        ]);

        $profile = $request->user()->designerProfile()->first();
        abort_if($profile?->status === 'suspended', 403);
        $designers->apply($request->user(), $data + ['accept_terms' => true]);

        return redirect()->route('designer.index')->with('status', trans('marketplace.portal.applied'));
    }

    public function createTheme(Request $request): Response
    {
        $this->profile($request);

        return $this->themeForm(null);
    }

    public function editTheme(Request $request, Theme $theme): Response
    {
        $profile = $this->profile($request);
        abort_unless($theme->designer_id === $profile->user_id, 403);

        return $this->themeForm($theme);
    }

    public function storeTheme(Request $request): RedirectResponse
    {
        $theme = $this->studio->saveTheme($this->profile($request), null, $this->themeData($request));

        return redirect()->route('designer.index')->with('status', trans('marketplace.portal.theme_saved', ['name' => $theme->name_en]));
    }

    public function updateTheme(Request $request, Theme $theme): RedirectResponse
    {
        $profile = $this->profile($request);
        abort_unless($theme->designer_id === $profile->user_id, 403);
        $this->studio->saveTheme($profile, $theme, $this->themeData($request));

        return redirect()->route('designer.index')->with('status', trans('marketplace.portal.theme_saved', ['name' => $theme->name_en]));
    }

    public function createTemplate(Request $request): Response|RedirectResponse
    {
        $profile = $this->profile($request);
        if (! Theme::query()->where('designer_id', $profile->user_id)->exists()) {
            return redirect()->route('designer.themes.create')->with('warning', trans('marketplace.portal.theme_first'));
        }

        return $this->templateForm($profile, null);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $profile = $this->profile($request);
        $template = $this->studio->createTemplate($profile, $this->templateData($request));

        return redirect()->route('designer.templates.edit', $template)->with('status', trans('marketplace.portal.template_created'));
    }

    public function editTemplate(Request $request, Template $template): Response
    {
        $profile = $this->profile($request);
        $this->own($profile, $template);

        return $this->templateForm($profile, $template);
    }

    public function updateTemplate(Request $request, Template $template): RedirectResponse
    {
        $profile = $this->profile($request);
        $this->own($profile, $template);
        $revision = $this->studio->saveDraft($profile, $template, $this->templateData($request));

        return back()->with('status', trans('marketplace.portal.draft_saved', ['number' => $revision->number]));
    }

    public function submit(Request $request, Template $template): RedirectResponse
    {
        $profile = $this->profile($request);
        $this->own($profile, $template);
        $this->studio->submit($profile, $template);

        return back()->with('status', trans('marketplace.portal.submitted'));
    }

    public function unpublish(Request $request, Template $template): RedirectResponse
    {
        $profile = $this->profile($request);
        $this->own($profile, $template);
        $this->studio->unpublish($template, $profile);

        return back()->with('status', trans('marketplace.portal.unpublished'));
    }

    public function republish(Request $request, Template $template): RedirectResponse
    {
        $profile = $this->profile($request);
        $this->own($profile, $template);
        $this->studio->republish($profile, $template);

        return back()->with('status', trans('marketplace.portal.republished'));
    }

    /**
     * POST /designer/templates/{template}/cover {cover: image}
     */
    public function cover(Request $request, Template $template): RedirectResponse
    {
        $profile = $this->profile($request);
        $this->own($profile, $template);
        $request->validate(['cover' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:'.config('marketplace.max_upload_kb', 4096), 'dimensions:min_width=400,min_height=400']]);
        $this->studio->uploadCover($profile, $template, $request->file('cover'));

        return back()->with('status', trans('marketplace.portal.cover_saved'));
    }

    private function themeForm(?Theme $theme): Response
    {
        $locale = App::getLocale();

        return Inertia::render('designer/ThemeEdit', [
            'theme' => $theme ? [
                'id' => $theme->id,
                'name_ar' => $theme->name_ar,
                'name_en' => $theme->name_en,
                'tokens' => ['colors' => (object) ($theme->tokens['colors'] ?? []), 'radius' => $theme->tokens['radius'] ?? null],
                'fonts' => $theme->fonts,
            ] : null,
            'required_colors' => CatalogValidator::REQUIRED_COLORS,
            'optional_colors' => CatalogValidator::OPTIONAL_COLORS,
            'font_roles' => CatalogValidator::FONT_ROLES,
            'font_suggestions' => ThemeResource::FONT_SUGGESTIONS,
            'urls' => ['save' => $theme ? route('designer.themes.update', $theme, false) : route('designer.themes.store', absolute: false)],
        ]);
    }

    private function templateForm(DesignerProfile $profile, ?Template $template): Response
    {
        $locale = App::getLocale();
        $name = fn ($m) => $locale === 'ar' ? $m->name_ar : $m->name_en;
        $revisions = $template?->revisions()->get() ?? collect();
        $latest = $revisions->first();
        $source = $latest ?? $template;

        $themes = Theme::query()->where('designer_id', $profile->user_id)->orderBy('name_en')->get();
        $occasion = $source?->occasion ?? 'wedding';
        $config = $source?->default_config ?? DraftCreator::configFor($occasion, DraftCreator::defaultTemplate($occasion));

        return Inertia::render('designer/TemplateEdit', [
            'template' => $template ? [
                'id' => $template->id,
                'status' => $template->marketplace_status,
                'published' => $template->published_at !== null,
                'cover' => $this->coverUrl($template),
                'live_revision' => $revisions->firstWhere('id', $template->live_revision_id)?->number,
            ] : null,
            'revision' => $latest ? [
                'id' => $latest->id,
                'number' => $latest->number,
                'status' => $latest->status,
                'rejection_reason' => $latest->rejection_reason,
                'editable' => $latest->isEditable() || $latest->status === 'approved' || $latest->status === 'superseded',
                'updated_at' => $latest->updated_at?->toIso8601String(),
                'preview_url' => route('designer.revisions.preview', $latest, false),
            ] : null,
            'revisions' => $revisions->map(fn (TemplateRevision $r) => [
                'number' => $r->number,
                'status' => $r->status,
                'rejection_reason' => $r->rejection_reason,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
                'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            ])->values(),
            'form' => [
                'name_ar' => $source?->name_ar ?? '',
                'name_en' => $source?->name_en ?? '',
                'description_ar' => $source?->description_ar ?? '',
                'description_en' => $source?->description_en ?? '',
                'occasion' => $occasion,
                'opening_id' => $source?->opening_id ?? 'envelope',
                'theme_id' => $source?->theme_id ?? $themes->first()?->id,
                'default_config' => $config,
                'prices' => (object) ($source?->prices ?? []),
                'included_in_signature' => (bool) ($source?->included_in_signature ?? false),
            ],
            'options' => [
                'occasions' => Event::OCCASIONS,
                'openings' => Opening::query()->where('active', true)->orderBy('id')->get()->map(fn (Opening $o) => ['id' => $o->id, 'name' => $name($o), 'tier' => $o->tier])->values(),
                'themes' => $themes->map(fn (Theme $t) => ['id' => $t->id, 'name' => $name($t), 'config' => $t->toConfig()])->values(),
                'currencies' => MarketplacePrices::bounds(),
                'share_bp' => $profile->shareBp(),
            ],
            'urls' => [
                'save' => $template ? route('designer.templates.update', $template, false) : route('designer.templates.store', absolute: false),
                'submit' => $template ? route('designer.templates.submit', $template, false) : null,
                'unpublish' => $template ? route('designer.templates.unpublish', $template, false) : null,
                'republish' => $template ? route('designer.templates.republish', $template, false) : null,
                'cover' => $template ? route('designer.templates.cover', $template, false) : null,
                'index' => route('designer.index', absolute: false),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function themeData(Request $request): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'tokens' => ['required', 'array'],
            'tokens.colors' => ['required', 'array'],
            'tokens.colors.*' => ['nullable', 'string', 'max:9'],
            'tokens.radius' => ['nullable', 'integer', 'min:0', 'max:32'],
            'fonts' => ['required', 'array'],
            'fonts.*' => ['nullable', 'string', 'max:40'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function templateData(Request $request): array
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'description_ar' => ['nullable', 'string', 'max:2000'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'occasion' => ['required', Rule::in(Event::OCCASIONS)],
            'opening_id' => ['required', 'string', 'max:40'],
            'theme_id' => ['required', 'string', 'max:64'],
            'default_config' => ['required'],
            'prices' => ['nullable', 'array'],
            'prices.*' => ['nullable', 'integer', 'min:0'],
            'included_in_signature' => ['boolean'],
        ]);

        // the JSON editor sends text: a parse error is a field error, not a 500
        if (is_string($data['default_config'])) {
            $decoded = json_decode($data['default_config'], true);
            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['default_config' => trans('marketplace.errors.json')]);
            }
            $data['default_config'] = $decoded;
        }

        return $data;
    }

    private function profile(Request $request): DesignerProfile
    {
        return Designers::approvedProfile($request->user());
    }

    private function own(DesignerProfile $profile, Template $template): void
    {
        abort_unless($template->designer_id !== null && $template->designer_id === $profile->user_id, 403);
    }

    private function coverUrl(Template $template): ?string
    {
        return $template->cover_path ? Storage::disk(config('filesystems.media_disk', 'public'))->url($template->cover_path) : null;
    }

    private function termsHtml(string $locale): string
    {
        $file = resource_path("content/legal/{$locale}/designers.md");

        return is_file($file) ? Str::markdown((string) file_get_contents($file), ['html_input' => 'strip', 'allow_unsafe_links' => false]) : '';
    }
}
