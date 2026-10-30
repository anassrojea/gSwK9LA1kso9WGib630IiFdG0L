<?php

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\HallLayout;
use App\Models\Organization;
use App\Models\OrgMember;
use App\Services\Org\OrgPresenter;
use App\Services\Org\WhiteLabel;
use App\Services\Seating\HallLayouts;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization area for venues, planners and companies (phase 8A):
 *
 *   GET  /org                       the user's organizations, and "create one"
 *   POST /org                       create (the creator is the owner)
 *   GET  /org/{org}                 the venue dashboard (plan section 4.2): calendar of the org's
 *                                   events, the team and the hall layouts
 *   GET  /org/{org}/settings        profile, logo, white label (owner)
 *   POST /org/{org}/settings        save them (multipart for the logo)
 *
 * Authorized through OrganizationPolicy.
 */
class OrganizationController extends Controller
{
    public const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp']; // no SVG: it could carry script

    public function __construct(private OrgPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('org/Index', [
            'organizations' => $user->organizations()->with('plan')->orderBy('name')->get()->map(fn (Organization $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'type' => $o->type,
                'city' => $o->city,
                'logo_url' => $o->logo_url,
                'role' => $o->pivot->role,
                'tier' => $o->plan_id,
                'events' => $o->events()->count(),
                'url' => route('org.show', $o, false),
            ])->values(),
            'types' => Organization::TYPES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('org/Create', ['types' => Organization::TYPES]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Organization::class);
        $data = $this->validated($request, creating: true);
        $user = $request->user();

        $org = DB::transaction(function () use ($data, $user, $request) {
            $org = Organization::query()->create(collect($data)->only(['name', 'type', 'city', 'contact_phone', 'contact_email'])->all());
            OrgMember::query()->create(['org_id' => $org->getKey(), 'user_id' => $user->getKey(), 'role' => OrgMember::ROLE_OWNER]);
            if ($request->hasFile('logo')) {
                $this->storeLogo($org, $request->file('logo'));
            }
            AuditLog::record('org.created', $org, ['type' => $org->type], $user);

            return $org;
        });

        return redirect()->route('org.show', $org)->with('status', trans('org.created'));
    }

    /**
     * The venue dashboard. ?month=YYYY-MM picks the calendar month.
     */
    public function show(Request $request, Organization $organization): Response
    {
        Gate::authorize('view', $organization);
        $user = $request->user();

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? CarbonImmutable::parse($request->query('month').'-01') : CarbonImmutable::now()->startOfMonth();
        // the month's grid starts and ends on whole weeks
        $from = $month->startOfMonth()->subDays(7);
        $to = $month->endOfMonth()->addDays(7);

        $memberIds = OrgMember::query()->where('org_id', $organization->getKey())->pluck('user_id');
        $events = Event::query()
            ->where('org_id', $organization->getKey())
            ->where(fn ($q) => $q->whereBetween('starts_at', [$from, $to])->orWhereNull('starts_at'))
            ->with(['owner:id,name,email', 'invitation:id,event_id,published_at', 'hallLayout:id,name'])
            ->withCount([
                'households as invited',
                'households as replied' => fn ($q) => $q->whereHas('rsvp'),
            ])
            ->withSum(['checkins as arrived'], 'people_in')
            ->orderBy('starts_at')
            ->limit(300)
            ->get();

        $attending = DB::table('households')
            ->join('rsvps', 'rsvps.household_id', '=', 'households.id')
            ->whereIn('households.event_id', $events->modelKeys())
            ->where('rsvps.attending', true)
            ->groupBy('households.event_id')
            ->selectRaw('households.event_id, sum(rsvps.people_count) as people')
            ->pluck('people', 'event_id');

        return Inertia::render('org/Show', [
            'org' => $this->presenter->present($organization, $user),
            'month' => $month->format('Y-m'),
            'events' => $events->map(fn (Event $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'occasion' => $e->occasion,
                'status' => $e->status,
                'starts_at' => $e->starts_at?->toIso8601String(),
                'timezone' => $e->timezone,
                'hall' => $e->hallLayout?->name,
                'published' => (bool) $e->invitation?->published_at,
                // the couple, once they accepted the client invitation (they own the event then)
                'client' => $e->owner_id && ! $memberIds->contains($e->owner_id) ? $e->owner?->name : null,
                'stats' => [
                    'invited' => (int) $e->invited,
                    'replied' => (int) $e->replied,
                    'attending' => (int) ($attending[$e->id] ?? 0),
                    'arrived' => (int) ($e->arrived ?? 0),
                ],
                'url' => route('org.events.show', [$organization, $e], false),
            ])->values(),
            'team' => $this->team($organization),
            'halls' => HallLayout::query()->where('org_id', $organization->getKey())->withCount('events')->orderBy('name')->get()
                ->map(fn (HallLayout $h) => HallLayouts::summary($h) + [
                    'events' => $h->events_count,
                    'url' => route('dashboard.halls.edit', $h, false),
                ])->values(),
            'occasions' => Event::OCCASIONS,
        ]);
    }

    public function settings(Request $request, Organization $organization): Response
    {
        Gate::authorize('update', $organization);

        return Inertia::render('org/Settings', [
            'org' => $this->presenter->present($organization, $request->user()),
            'types' => Organization::TYPES,
        ]);
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('update', $organization);
        $data = $this->validated($request, creating: false);

        $whiteLabel = $organization->white_label ?? [];
        foreach (['brand_color', 'sender_name'] as $key) {
            if ($request->exists($key)) {
                $whiteLabel[$key] = filled($data[$key] ?? null) ? trim((string) $data[$key]) : null;
            }
        }

        $organization->fill(collect($data)->only(['name', 'type', 'city', 'contact_phone', 'contact_email'])->all());
        $organization->white_label = array_filter($whiteLabel, fn ($v) => $v !== null) ?: null;

        if ($request->boolean('remove_logo')) {
            $this->deleteLogo($organization);
        }
        if ($request->hasFile('logo')) {
            $this->storeLogo($organization, $request->file('logo'));
        }

        $changed = array_keys($organization->getDirty());
        $organization->save();
        if ($changed) {
            AuditLog::record('org.updated', $organization, ['fields' => $changed], $request->user());
        }

        return back()->with('status', trans('org.settings.saved'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function team(Organization $org): array
    {
        return OrgMember::query()
            ->where('org_id', $org->getKey())
            ->with('user:id,name,email')
            ->orderByRaw("case role when 'owner' then 0 when 'staff' then 1 else 2 end")
            ->orderBy('created_at')
            ->get()
            ->map(fn (OrgMember $m) => [
                'user_id' => $m->user_id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'role' => $m->role,
                'since' => $m->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:120'],
            'type' => [$required, Rule::in(Organization::TYPES)],
            'city' => ['nullable', 'string', 'max:80'],
            'contact_phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s٠-٩]{6,32}$/u'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'file', 'max:'.(int) config('org.logo_max_kb', 1024), 'mimetypes:'.implode(',', array_keys(self::LOGO_TYPES))],
            'brand_color' => ['nullable', 'string', 'regex:'.WhiteLabel::COLOR_PATTERN],
            'sender_name' => ['nullable', 'string', 'max:60', 'not_regex:/[<>@\r\n"]/'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);
    }

    private function storeLogo(Organization $org, UploadedFile $file): void
    {
        $extension = self::LOGO_TYPES[(string) $file->getMimeType()] ?? 'png';
        $disk = Storage::disk(config('filesystems.media_disk', 'public'));
        $path = $disk->putFileAs("orgs/{$org->getKey()}", $file, Str::uuid().'.'.$extension, ['visibility' => 'public']);
        abort_unless($path, 500);

        $this->deleteLogo($org);
        $org->forceFill(['logo_path' => $path, 'logo_url' => $disk->url($path)])->save();
    }

    private function deleteLogo(Organization $org): void
    {
        if ($org->logo_path) {
            Storage::disk(config('filesystems.media_disk', 'public'))->delete($org->logo_path);
        }
        $org->forceFill(['logo_path' => null, 'logo_url' => null]);
    }
}
