<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Marketing\VenueDirectoryController;
use App\Models\HallLayout;
use App\Models\Organization;
use App\Models\OrgMedia;
use App\Models\OrgMember;
use App\Models\SiteCounter;
use App\Models\VenueProfile;
use App\Services\Digits;
use App\Services\Seating\HallLayoutData;
use App\Services\Venues\Posters;
use App\Services\Venues\VenueDirectory;
use App\Services\Venues\VenueFeatures;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The venue's profile for the directory (phase 8B, plan section 13.1), for
 * the organization's owners and staff:
 *
 *   GET    /dashboard/venue?org=           the form, photos, halls to show, QR posters
 *   PUT    /dashboard/venue                save (listing needs the directory_listing feature)
 *   POST   /dashboard/venue/media          a photo or the logo (orgs/{org_id}/… on the media disk)
 *   DELETE /dashboard/venue/media/{media}
 *   GET    /dashboard/venue/poster.pdf     "Book your invitation with us", A4 or A5
 */
class VenueProfileController extends Controller
{
    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private VenueFeatures $features, private VenueDirectory $directory) {}

    public function edit(Request $request): Response
    {
        $orgs = $this->managed($request);
        $org = $orgs->firstWhere('id', $request->query('org')) ?? $orgs->first();

        $base = [
            'organizations' => $orgs->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name])->values(),
            'translations' => SeatingController::translations(),
            'options' => [
                'cities' => collect(config('venues.cities'))->keys()->map(fn ($c) => ['value' => $c, 'label' => __("venues.cities.{$c}")])->values(),
                'types' => collect(config('venues.types'))->map(fn ($t) => ['value' => $t, 'label' => __("venues.types.{$t}")])->values(),
                'amenities' => collect(config('venues.amenities'))->map(fn ($a) => ['value' => $a, 'label' => __("venues.amenities.{$a}")])->values(),
                'max_photos' => (int) config('venues.max_photos'),
            ],
        ];
        if (! $org) {
            return Inertia::render('venue/Profile', $base + ['org' => null]);
        }

        $profile = VenueProfile::query()->with('logo')->where('org_id', $org->id)->first();
        $media = OrgMedia::query()->where('org_id', $org->id)->orderBy('sort')->orderBy('created_at')->get();
        $logo = $profile?->logo ?? $media->where('kind', OrgMedia::KIND_LOGO)->last();

        return Inertia::render('venue/Profile', $base + [
            'org' => ['id' => $org->id, 'name' => $org->name],
            'feature' => $this->features->has($org, VenueFeatures::DIRECTORY_LISTING),
            'profile' => [
                'exists' => $profile !== null,
                'slug' => $profile?->slug ?? VenueProfile::uniqueSlug($org->name),
                'name_en' => $profile?->name_en,
                'venue_type' => $profile?->venue_type ?? ($org->type === 'venue' ? 'hall' : 'other'),
                'city' => $profile?->city ?? VenueProfile::orgCity($org) ?? 'riyadh',
                'area' => $profile?->area,
                'description_ar' => $profile?->description_ar,
                'description_en' => $profile?->description_en,
                'capacity' => $profile?->capacity,
                'amenities' => $profile?->amenities ?? [],
                'whatsapp' => $profile?->whatsapp ?? $org->getAttribute('contact_phone'),
                'map_url' => $profile?->map_url,
                'listed' => (bool) $profile?->listed,
                'status' => $profile?->status ?? VenueProfile::STATUS_DRAFT,
            ],
            'public' => $profile && $this->directory->isPublic($profile),
            'photos' => $media->where('kind', OrgMedia::KIND_PHOTO)->values()->map(fn (OrgMedia $m) => ['id' => $m->id, 'url' => $m->url()]),
            'logo' => $logo ? ['id' => $logo->id, 'url' => $logo->url()] : null,
            'halls' => HallLayout::query()->where('org_id', $org->id)->orderBy('name')->get()->map(fn (HallLayout $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'public' => $h->public,
                'tables' => count($h->layout['tables'] ?? []),
                'seats' => HallLayoutData::totalSeats($h->layout ?? []),
            ]),
            'scans' => $profile ? SiteCounter::total(VenueDirectoryController::SCAN_COUNTER, $profile->getKey()) : 0,
            'urls' => [
                'page' => $profile ? VenueDirectory::url($profile) : null,
                'poster_a4' => $profile ? route('dashboard.venue.poster', ['org' => $org->id, 'size' => 'A4'], false) : null,
                'poster_a5' => $profile ? route('dashboard.venue.poster', ['org' => $org->id, 'size' => 'A5'], false) : null,
                'short' => $profile ? VenueDirectoryController::posterUrl($profile) : null,
                'halls' => route('dashboard.halls.index', absolute: false),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $org = $this->organization($request);
        $profile = VenueProfile::query()->where('org_id', $org->id)->first();

        $data = $request->validate([
            'slug' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('venue_profiles', 'slug')->ignore($profile?->id)],
            'name_en' => ['nullable', 'string', 'max:120'],
            'venue_type' => ['required', Rule::in(config('venues.types'))],
            'city' => ['required', Rule::in(array_keys(config('venues.cities')))],
            'area' => ['nullable', 'string', 'max:80'],
            'description_ar' => ['nullable', 'string', 'max:3000'],
            'description_en' => ['nullable', 'string', 'max:3000'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'amenities' => ['nullable', 'array', 'max:30'],
            'amenities.*' => ['string', Rule::in(config('venues.amenities'))],
            'whatsapp' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9٠-٩\s-]{8,20}$/u'],
            'map_url' => ['nullable', 'string', 'max:500', 'url:https'],
            'listed' => ['boolean'],
            'public_halls' => ['nullable', 'array'],
            'public_halls.*' => ['uuid'],
        ], ['slug.unique' => __('venues.errors.slug_taken')]);

        $listed = (bool) ($data['listed'] ?? false);
        if ($listed && ! $this->features->has($org, VenueFeatures::DIRECTORY_LISTING)) {
            throw ValidationException::withMessages(['listed' => __('venues.errors.needs_plan')]);
        }

        DB::transaction(function () use ($org, $profile, $data, $listed) {
            $status = $profile?->status ?? VenueProfile::STATUS_DRAFT;
            if ($listed && $status === VenueProfile::STATUS_DRAFT) {
                $status = VenueProfile::STATUS_PENDING;
            } elseif (! $listed && $status === VenueProfile::STATUS_PENDING) {
                $status = VenueProfile::STATUS_DRAFT;
            }

            $values = [
                'slug' => $data['slug'],
                'name_en' => static::clean($data['name_en'] ?? null),
                'venue_type' => $data['venue_type'],
                'city' => $data['city'],
                'area' => static::clean($data['area'] ?? null),
                'description_ar' => static::clean($data['description_ar'] ?? null, multiline: true),
                'description_en' => static::clean($data['description_en'] ?? null, multiline: true),
                'capacity' => $data['capacity'] ?? null,
                'amenities' => array_values(array_unique($data['amenities'] ?? [])),
                'whatsapp' => isset($data['whatsapp']) ? Digits::toWestern(preg_replace('/[\s-]+/', '', $data['whatsapp'])) : null,
                'map_url' => $data['map_url'] ?? null,
                'listed' => $listed,
                'status' => $status,
            ];

            if ($profile) {
                $profile->update($values);
            } else {
                $profile = VenueProfile::query()->create($values + [
                    'org_id' => $org->id,
                    'logo_id' => OrgMedia::query()->where('org_id', $org->id)->where('kind', OrgMedia::KIND_LOGO)->latest()->value('id'),
                ]);
            }

            $ids = $data['public_halls'] ?? [];
            HallLayout::query()->where('org_id', $org->id)->update(['public' => false]);
            if ($ids !== []) {
                HallLayout::query()->where('org_id', $org->id)->whereIn('id', $ids)->update(['public' => true]);
            }
        });

        return redirect()->route('dashboard.venue', ['org' => $org->id])->with('status', __('venues.dashboard.saved'));
    }

    public function storeMedia(Request $request): RedirectResponse
    {
        $org = $this->organization($request);
        $request->validate([
            'kind' => ['required', Rule::in([OrgMedia::KIND_LOGO, OrgMedia::KIND_PHOTO])],
            'file' => ['required', 'file', 'max:'.(int) config('venues.max_photo_kb')],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $extension = self::IMAGE_TYPES[(string) $file->getMimeType()] ?? throw ValidationException::withMessages(['file' => __('venues.errors.photo_type')]);
        $size = @getimagesize($file->getRealPath());
        if (! $size) {
            throw ValidationException::withMessages(['file' => __('venues.errors.photo_type')]);
        }

        $kind = $request->string('kind')->toString();
        $max = (int) config('venues.max_photos');
        if ($kind === OrgMedia::KIND_PHOTO && OrgMedia::query()->where('org_id', $org->id)->where('kind', OrgMedia::KIND_PHOTO)->count() >= $max) {
            throw ValidationException::withMessages(['file' => __('venues.errors.too_many_photos', ['count' => Digits::localize($max)])]);
        }

        $id = (string) Str::uuid();
        $key = OrgMedia::disk()->putFileAs("orgs/{$org->id}", $file, "{$id}.{$extension}", ['visibility' => 'public']);
        abort_unless($key, 500);

        DB::transaction(function () use ($org, $kind, $key, $size, $file, $id) {
            $media = new OrgMedia([
                'org_id' => $org->id,
                'kind' => $kind,
                'storage_key' => $key,
                'width' => $size[0],
                'height' => $size[1],
                'bytes' => $file->getSize(),
                'sort' => (int) OrgMedia::query()->where('org_id', $org->id)->where('kind', $kind)->max('sort') + 1,
            ]);
            $media->id = $id;
            $media->save();

            if ($kind === OrgMedia::KIND_LOGO) {
                // one logo: the new one replaces the old
                OrgMedia::query()->where('org_id', $org->id)->where('kind', OrgMedia::KIND_LOGO)->whereKeyNot($id)->get()->each->delete();
                VenueProfile::query()->where('org_id', $org->id)->update(['logo_id' => $id]);
            }
        });

        return redirect()->route('dashboard.venue', ['org' => $org->id]);
    }

    public function destroyMedia(Request $request, OrgMedia $orgMedia): RedirectResponse
    {
        $media = $orgMedia;
        $org = $media->organization ?? abort(404);
        Gate::authorize('createEvents', $org);

        $media->delete();

        return redirect()->route('dashboard.venue', ['org' => $org->id]);
    }

    public function poster(Request $request, Posters $posters): HttpResponse
    {
        $org = $this->organization($request);
        $profile = VenueProfile::query()->with(['organization', 'logo'])->where('org_id', $org->id)->firstOrFail();
        $size = in_array($request->query('size'), Posters::SIZES, true) ? $request->query('size') : 'A4';

        abort_unless($posters->available(), 503, __('seating.pdf.unavailable'));
        try {
            $pdf = $posters->render($posters->venueHtml($profile, $size), 'venue');
        } catch (RuntimeException) {
            abort(503, __('seating.pdf.unavailable'));
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="venue-qr-'.$profile->slug.'-'.strtolower($size).'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** The organization in the request (org / org_id), which the user must manage. */
    private function organization(Request $request): Organization
    {
        $id = (string) ($request->input('org_id') ?? $request->query('org'));
        $org = Str::isUuid($id) ? Organization::query()->find($id) : null;
        abort_unless($org, 404);
        Gate::authorize('createEvents', $org);

        return $org;
    }

    /**
     * Organizations whose profile this user edits (owner or staff), admins see all.
     *
     * @return Collection<int, Organization>
     */
    private function managed(Request $request): Collection
    {
        $user = $request->user();

        return $user->isAdmin()
            ? Organization::query()->orderBy('name')->limit(100)->get()
            : Organization::query()
                ->whereIn('id', OrgMember::query()->where('user_id', $user->getKey())->whereIn('role', [OrgMember::ROLE_OWNER, OrgMember::ROLE_STAFF])->select('org_id'))
                ->orderBy('name')
                ->get();
    }

    private static function clean(?string $value, bool $multiline = false): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strip_tags($value);
        $value = $multiline ? preg_replace("/[ \t]+/u", ' ', preg_replace("/\R{3,}/u", "\n\n", $value)) : preg_replace('/\s+/u', ' ', $value);
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
