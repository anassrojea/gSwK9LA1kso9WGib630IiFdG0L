<?php

namespace App\Http\Controllers\Wizard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Household;
use App\Models\Media;
use App\Models\Opening;
use App\Models\OpenLink;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Template;
use App\Models\Theme;
use App\Services\Account\EmailVerification;
use App\Services\Entitlements;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\Journey\SaveTheDate;
use App\Services\OpenLinks;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\Currencies;
use App\Services\Payments\OrderPresenter;
use App\Services\Payments\PriceList;
use App\Services\PublishGate;
use App\Services\Storage\StoragePeriod;
use App\Services\Wizard\DraftCreator;
use App\Services\Wizard\PlanAdvisor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The creation wizard (plan section 3): occasion → design → details → guests
 * → review & send. Each step is its own Inertia page under Pages/create/.
 * Access to {event} is checked by the "draft" middleware (EnsureDraftAccess).
 */
class WizardController extends Controller
{
    public const STEPS = ['occasion', 'design', 'details', 'guests', 'review'];

    /**
     * GET /create: step 1 before a draft exists.
     */
    public function create(): Response
    {
        return Inertia::render('create/Occasion', [
            'event' => null,
            'config' => null,
            'occasions' => Event::WIZARD_OCCASIONS,
            'steps' => self::STEPS,
        ]);
    }

    /**
     * POST /create {occasion, template_id?}: a draft for a guest or a user.
     */
    public function store(Request $request, DraftCreator $drafts): RedirectResponse
    {
        $data = $request->validate([
            'occasion' => ['required', Rule::in(Event::WIZARD_OCCASIONS)],
            'template_id' => ['nullable', 'string', Rule::exists('templates', 'id')->where('active', true)],
        ]);

        $event = $drafts->create(
            $data['occasion'],
            $request->user(),
            isset($data['template_id']) ? Template::query()->find($data['template_id']) : null,
        );

        return redirect()->route('create.step', [$event, 'design']);
    }

    /**
     * GET /create/{event}/{step}
     */
    public function show(Request $request, Event $event, string $step, Entitlements $entitlements, PlanAdvisor $plans): Response
    {
        $invitation = $event->invitation;
        abort_unless($invitation, 404);

        // the editor works on the current schema version; saving writes it back as v2
        $config = InvitationConfig::upgrade($invitation->config);

        $props = [
            'event' => [
                'id' => $event->getKey(),
                'title' => $event->title,
                'occasion' => $event->occasion,
                'status' => $event->status,
                'published' => $invitation->published_at !== null,
            ],
            'config' => $config,
            'templateId' => $invitation->template_id,
            'steps' => self::STEPS,
            'step' => $step,
            'urls' => [
                'config' => route('events.config.update', $event, false),
                'media' => route('events.media.store', $event, false),
                'preview' => route('create.preview', $event, false),
            ],
            'tiers' => $this->tiers($config),
        ];

        $props += match ($step) {
            'occasion' => ['occasions' => Event::WIZARD_OCCASIONS],
            'design' => $this->catalog(),
            'details' => ['media' => $this->media($event)],
            'guests' => $this->guests($event, $entitlements),
            'review' => $this->review($request, $event, $config, $plans, $entitlements),
        };

        return Inertia::render('create/'.ucfirst($step), $props);
    }

    /**
     * POST /create/{event}/publish (account required): sets published_at and
     * status=active, then the review step shows each guest's personal link.
     * The event's plan must cover what the invitation uses (PublishGate): a
     * Free invitation publishes at once, anything else after its order is
     * paid (card) or approved (transfer).
     */
    public function publish(Event $event, PublishGate $gate, StoragePeriod $storage): RedirectResponse
    {
        $invitation = $event->invitation;
        abort_unless($invitation, 404);

        // publishing needs a verified email (drafts don't): the review step shows the resend prompt
        EmailVerification::ensure(request(), 'publish');

        // something to send: personal links, or the open link for everyone
        if (! $event->households()->exists() && ! $event->openLink()->where('enabled', true)->exists()) {
            return back()->withErrors(['publish' => trans('app.wizard.review.need_guests_or_link')]);
        }

        $check = $gate->check($event, $invitation->config);
        if (! $check['allowed']) {
            return back()->withErrors(['publish' => $check['pending_order']
                ? trans('app.checkout.waiting_publish', ['code' => $check['pending_order']])
                : trans('app.checkout.payment_needed')]);
        }

        $invitation->published_at ??= now();
        $invitation->save();

        if ($event->status === 'draft') {
            $event->status = 'active';
        }
        // photos and uploads are kept until the event's date + the plan's storage days (plan section 11)
        $storage->apply($event);
        $event->save();

        return redirect()->route('create.step', [$event, 'review'])->with('status', trans('app.wizard.review.published'));
    }

    private function catalog(): array
    {
        $locale = App::getLocale();
        $name = fn ($model) => $locale === 'ar' ? $model->name_ar : $model->name_en;

        $openings = Opening::query()->where('active', true)->orderBy('id')->get();
        $themes = Theme::query()->where('active', true)->orderBy('id')->get();
        $openingTiers = Opening::query()->pluck('tier', 'id');
        $themeTiers = Theme::query()->pluck('tier', 'id');

        return [
            'templates' => Template::query()->where('active', true)->where('occasion', '!=', 'thank_you')->orderBy('sort')->orderBy('id')->get()
                ->map(fn (Template $t) => [
                    'id' => $t->id,
                    'occasion' => $t->occasion,
                    'name' => $name($t),
                    'tier' => $this->maxTier([$t->tier, $openingTiers[$t->opening_id] ?? 'free', $themeTiers[$t->theme_id] ?? 'free']),
                    'opening' => $t->default_config['opening'] ?? ['id' => $t->opening_id],
                    'theme' => $t->default_config['theme'] ?? null,
                    'effects' => $t->default_config['effects'] ?? null,
                ])->values(),
            'openings' => $openings->map(fn (Opening $o) => ['id' => $o->id, 'name' => $name($o), 'tier' => $o->tier])->values(),
            'themes' => $themes->map(fn (Theme $t) => ['id' => $t->id, 'name' => $name($t), 'tier' => $t->tier, 'config' => $t->toConfig()])->values(),
        ];
    }

    /**
     * @return array<string, array{url: string, kind: string}>
     */
    private function media(Event $event): array
    {
        $disk = Storage::disk(config('filesystems.media_disk', 'public'));

        return $event->media()->where('hidden', false)->latest()->limit(200)->get()
            ->mapWithKeys(fn (Media $m) => [$m->id => ['url' => $disk->url($m->storage_key), 'kind' => $m->kind]])
            ->all();
    }

    private function guests(Event $event, Entitlements $entitlements): array
    {
        return [
            'households' => $this->households($event),
            'limit' => $entitlements->limit($event, 'guests'),
            'seats' => (int) $event->households()->sum('seats_allowed'),
        ];
    }

    private function review(Request $request, Event $event, array $config, PlanAdvisor $plans, Entitlements $entitlements): array
    {
        if (! $request->user()) {
            // after "Create account" / "Log in" Fortify sends the host back here, now owning the draft
            $request->session()->put('url.intended', route('create.step', [$event, 'review']));
        }

        $advice = $plans->advise($event, $config);
        $locale = $config['locale'];
        $gate = app(PublishGate::class);
        $check = $gate->check($event, $config);
        $canSend = $gate->canSendPersonalLinks($event);
        $presenter = app(OrderPresenter::class);

        // the order to show: the one just back from a card checkout (?order=), else the event's pending one
        $order = null;
        if ($request->user()) {
            $orders = Order::query()->where('event_id', $event->getKey())->where('user_id', $request->user()->getKey())->with('latestReceipt');
            $order = ($request->filled('order') ? (clone $orders)->where('code', $request->string('order')->toString())->first() : null)
                ?? (clone $orders)->pending()->latest()->first();
        }

        return [
            // families invited by name; replies through the open link have no link to send.
            // Their links (and WhatsApp buttons) need Premium: none are handed out on Free.
            'households' => $this->households($event, withLinks: $event->invitation->published_at !== null && $canSend, locale: $locale, personalOnly: true),
            'seats' => (int) $event->households()->whereRaw('not (? = any(tags))', [OpenLink::TAG])->sum('seats_allowed'),
            'limit' => $entitlements->limit($event, 'guests'),
            'plan' => $advice['plan'] ? [
                'id' => $advice['plan']->id,
                'name' => $advice['plan']->name,
                'price_cents' => $advice['plan']->price_cents,
                'currency' => $advice['plan']->currency,
                'guests' => $advice['plan']->limits['guests'] ?? null,
            ] : null,
            'planReasons' => $advice['reasons'],
            'plans' => Plan::query()->where('active', true)->whereNull('interval')->orderBy('price_cents')->get()
                ->map(fn (Plan $p) => ['id' => $p->id, 'name' => $p->name, 'price_cents' => $p->price_cents, 'currency' => $p->currency, 'guests' => $p->limits['guests'] ?? null])
                ->values(),
            // what the plan must cover before publishing (PublishGate) and the checkout to get there
            'gate' => ['allowed' => $check['allowed'], 'missing' => $check['missing'], 'current' => $check['current'], 'required' => $check['required']],
            'canSendPersonal' => $canSend,
            // a save-the-date (phase 8D) is bought as its own product, not with a plan
            'checkout' => $request->user() && ! $check['allowed']
                ? app(CheckoutService::class)->options($event, $request->user(), SaveTheDate::is($config) ? [SaveTheDate::FEATURE] : null)
                : null,
            'saveTheDate' => SaveTheDate::is($config),
            'order' => $order ? $presenter->present($order) : null,
            'checkoutOutcome' => $request->query('checkout'),
            // the advised plans' prices in the host's (or the event's country's) currency, before any sign-up
            'priceCurrency' => $currency = ($request->user()?->currency ?? Currencies::forCountry(Currencies::countryForTimezone($event->timezone))),
            'localPrices' => Plan::query()->with('prices')->where('active', true)->whereNull('interval')->get()
                ->mapWithKeys(fn (Plan $p) => [$p->id => app(PriceList::class)->amount($p, $currency)]),
            'publishUrl' => route('create.publish', $event, false),
            // only for the signed-in host: the endpoints sit behind auth + can:update,event
            'openLink' => $request->user() ? app(OpenLinks::class)->payload($event) : null,
        ];
    }

    private function households(Event $event, bool $withLinks = false, string $locale = 'ar', bool $personalOnly = false): array
    {
        return $event->households()
            ->when($personalOnly, fn ($q) => $q->whereRaw('not (? = any(tags))', [OpenLink::TAG]))
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(function (Household $h) use ($withLinks, $event, $locale) {
                $row = ['id' => $h->id, 'name' => $h->display_name, 'seats' => $h->seats_allowed];
                if ($withLinks) {
                    // the short guest domain when configured (daawa.link), else the app URL
                    $row['link'] = rtrim((string) config('app.invite_url'), '/').route('invite.show', $h->token, false);
                    $row['message'] = trans('app.wizard.review.whatsapp_message', [
                        'name' => $h->display_name,
                        'title' => $event->title,
                        'link' => $row['link'],
                    ], $locale);
                }

                return $row;
            })->all();
    }

    /**
     * Tiers of what the host picked, for the badges and the price hint.
     */
    private function tiers(array $config): array
    {
        return [
            'opening' => Opening::query()->whereKey($config['opening']['id'])->value('tier') ?? 'free',
            'theme' => Theme::query()->whereKey($config['theme']['id'])->value('tier') ?? 'free',
        ];
    }

    private function maxTier(array $tiers): string
    {
        return collect($tiers)->sortByDesc(fn ($t) => PlanAdvisor::TIERS[$t] ?? 0)->first() ?? 'free';
    }
}
