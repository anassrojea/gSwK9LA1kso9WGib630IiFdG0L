<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Entitlements;
use App\Services\Venues\Posters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /events/{event}/find-table.pdf?size=A4|A5 (phase 8B): the "Find your
 * table" QR for the hall's entrance, opening /e/{slug}/find on the guest's
 * phone. Part of the seating feature, like the seating chart.
 */
class FindTablePosterController extends Controller
{
    public function __invoke(Request $request, Event $event, Posters $posters, Entitlements $entitlements): Response
    {
        Gate::authorize('view', $event);
        abort_unless($entitlements->hasFeature($event, Entitlements::SEATING), 403, __('seating.locked.title'));

        $slug = $event->invitation?->slug;
        abort_if(! $slug || ! $event->invitation->published_at, 409, __('venues.find.no_slug'));
        abort_unless($posters->available(), 503, __('seating.pdf.unavailable'));

        $size = in_array($request->query('size'), Posters::SIZES, true) ? $request->query('size') : 'A4';
        $url = rtrim((string) config('app.invite_url'), '/').route('invite.find', $slug, false);

        try {
            $pdf = $posters->render($posters->findTableHtml($event, $url, $size), 'find');
        } catch (RuntimeException) {
            abort(503, __('seating.pdf.unavailable'));
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="find-your-table-'.$slug.'-'.strtolower($size).'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
