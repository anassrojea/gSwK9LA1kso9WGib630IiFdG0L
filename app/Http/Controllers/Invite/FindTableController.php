<?php

namespace App\Http\Controllers\Invite;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\Invitation;
use App\Services\Digits;
use App\Services\InvitationConfig\ThemeStyles;
use App\Services\Venues\FindTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Find your table" (phase 8B, plan section 9 item 2), behind the QR poster
 * at the hall's entrance:
 *
 *   GET  /e/{slug}/find   the page (no guest data in it, so it can be cached)
 *   POST /e/{slug}/find   {name?, phone?, last4?} → the table and a hall map
 *
 * Matching rules and privacy: App\Services\Venues\FindTable.
 */
class FindTableController extends Controller
{
    public function __construct(private FindTable $finder) {}

    public function show(string $slug): Response
    {
        [$invitation, $config] = $this->resolve($slug);
        $state = $this->finder->state($invitation->event);
        $locale = $config['locale'];

        return response()->view('invite.find-table', [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'state' => $state,
            'title' => WallController::couple($config, $invitation->event->title),
            'themeVars' => ThemeStyles::cssVariables($config['theme']),
            'fontsUrl' => ThemeStyles::fontsUrl($config['theme']),
            'lookupUrl' => route('invite.find.lookup', $slug, false),
        ], $state === FindTable::STATE_UNAVAILABLE ? 404 : 200)->header('Cache-Control', 'public, max-age=60, s-maxage=60');
    }

    public function lookup(Request $request, string $slug): JsonResponse
    {
        [$invitation] = $this->resolve($slug);
        $event = $invitation->event;

        $state = $this->finder->state($event);
        if ($state !== FindTable::STATE_OPEN) {
            return response()->json(['error' => $state, 'message' => __("venues.find.{$state}")], $state === FindTable::STATE_CLOSED ? 423 : 404);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'last4' => ['nullable', 'string', 'max:8'],
        ]);

        $result = $this->finder->lookup($event, $data['name'] ?? null, $data['phone'] ?? null, $data['last4'] ?? null);

        if (! $result['found']) {
            return response()->json(['found' => false, 'message' => __('venues.find.not_found')]);
        }
        if (! $result['seated']) {
            return response()->json(['found' => true, 'seated' => false, 'message' => __('venues.find.no_seat')]);
        }

        return response()->json([
            'found' => true,
            'seated' => true,
            'table' => $result['table'],
            'label' => __('venues.find.table', ['n' => Digits::localize($result['table'], app()->getLocale())]),
            'map' => $result['map'],
        ]);
    }

    /**
     * @return array{0: Invitation, 1: array}
     */
    private function resolve(string $slug): array
    {
        $invitation = Invitation::query()->with('event.hallLayout')->where('slug', $slug)->first();
        abort_if(! $invitation?->published_at || $invitation->event->isArchived(), 404);

        $config = $invitation->renderConfig();
        SetLocale::apply($config['locale']);

        return [$invitation, $config];
    }
}
