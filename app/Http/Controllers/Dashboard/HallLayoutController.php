<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\HallLayout;
use App\Models\Organization;
use App\Models\OrgMember;
use App\Services\HallGenerator;
use App\Services\Org\OrgPlans;
use App\Services\Seating\HallLayoutData;
use App\Services\Seating\HallLayouts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Venue halls (plan section 4.2, light version; full Venue Pro is phase 8):
 * organization owners and staff keep their halls' layouts here, so every
 * event in the hall reuses them from the seating planner.
 */
class HallLayoutController extends Controller
{
    public function __construct(private HallLayouts $halls) {}

    /**
     * GET /dashboard/halls
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orgs = $this->managedOrganizations($request);

        $halls = HallLayout::query()
            ->with('organization:id,name')
            ->withCount('events')
            ->where(fn ($q) => $q->where('owner_id', $user->getKey())->orWhereIn('org_id', $orgs->pluck('id')))
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (HallLayout $h) => HallLayouts::summary($h) + [
                'events' => $h->events_count,
                'can_edit' => $user->can('update', $h),
                'layout' => $h->layout,
                'updated_at' => $h->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('halls/Index', [
            'halls' => $halls,
            'organizations' => $orgs->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name])->values(),
            'translations' => SeatingController::translations(),
        ]);
    }

    /**
     * GET /dashboard/halls/create
     */
    public function create(Request $request): Response
    {
        return $this->editor($request, null);
    }

    /**
     * GET /dashboard/halls/{hall}
     */
    public function edit(Request $request, HallLayout $hall): Response
    {
        Gate::authorize('update', $hall);

        return $this->editor($request, $hall);
    }

    /**
     * POST /dashboard/halls {org_id?, name, layout}
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'org_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:'.HallLayoutData::MAX_NAME],
            'layout' => ['required', 'array'],
        ]);

        $org = null;
        if (! empty($data['org_id'])) {
            $org = Organization::query()->findOrFail($data['org_id']);
            Gate::authorize('createEvents', $org);
            // the plan's saved hall layouts (phase 8A, OrgPlans: hall_layouts, null = unlimited)
            $max = app(OrgPlans::class)->limits($org)['hall_layouts'];
            if ($max !== null && $org->hallLayouts()->count() >= $max) {
                throw ValidationException::withMessages(['name' => trans('org.halls.limit', ['limit' => $max])]);
            }
        }

        $hall = HallLayout::query()->create([
            'org_id' => $org?->id,
            'owner_id' => $org ? null : $request->user()->getKey(),
            'name' => trim($data['name']),
            'layout' => HallLayoutData::normalize($data['layout'], $data['name']),
        ]);

        return redirect()->route('dashboard.halls.edit', $hall)->with('status', trans('seating.halls.saved'));
    }

    /**
     * PUT /dashboard/halls/{hall} {name, layout}
     */
    public function update(Request $request, HallLayout $hall): RedirectResponse
    {
        Gate::authorize('update', $hall);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.HallLayoutData::MAX_NAME],
            'layout' => ['required', 'array'],
        ]);

        $hall->update([
            'name' => trim($data['name']),
            'layout' => HallLayoutData::normalize($data['layout'], $data['name']),
        ]);

        return back()->with('status', trans('seating.halls.saved'));
    }

    /**
     * DELETE /dashboard/halls/{hall}: only while no event uses it.
     */
    public function destroy(HallLayout $hall): RedirectResponse
    {
        Gate::authorize('delete', $hall);

        if ($this->halls->inUse($hall) > 0) {
            throw ValidationException::withMessages(['hall' => trans('seating.halls.in_use')]);
        }

        $hall->delete();

        return redirect()->route('dashboard.halls.index')->with('status', trans('seating.halls.deleted'));
    }

    private function editor(Request $request, ?HallLayout $hall): Response
    {
        return Inertia::render('halls/Edit', [
            'hall' => $hall ? HallLayouts::summary($hall) + ['layout' => $hall->layout, 'events' => $this->halls->inUse($hall), 'org_id' => $hall->org_id] : null,
            'organizations' => $this->managedOrganizations($request)->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name])->values(),
            'limits' => ['tables' => HallGenerator::MAX_TABLES, 'chairs' => HallGenerator::MAX_CHAIRS],
            'translations' => SeatingController::translations(),
        ]);
    }

    /**
     * Organizations whose halls this user manages (owner or staff).
     *
     * @return Collection<int, Organization>
     */
    private function managedOrganizations(Request $request)
    {
        $user = $request->user();

        return $user->isAdmin()
            ? Organization::query()->orderBy('name')->limit(100)->get()
            : Organization::query()
                ->whereIn('id', OrgMember::query()->where('user_id', $user->getKey())->whereIn('role', [OrgMember::ROLE_OWNER, OrgMember::ROLE_STAFF])->select('org_id'))
                ->orderBy('name')
                ->get();
    }
}
