<?php

namespace App\Http\Controllers\Keepsakes;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Keepsakes\ThankYouCards;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The host edits the thank-you card (phase 8D):
 *
 *   PUT  /events/{event}/thank-you          {message, title?, signature?, photos[], show_on_invite}
 *   POST /events/{event}/thank-you/publish  {publish: bool}  (needs the thank_you add-on)
 */
class ThankYouController extends Controller
{
    public function update(Request $request, Event $event, ThankYouCards $cards): RedirectResponse
    {
        Gate::authorize('update', $event);
        abort_unless($event->invitation, 404);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:80'],
            'signature' => ['nullable', 'string', 'max:120'],
            'photos' => ['array', 'max:'.(int) config('keepsakes.thank_you.max_photos', 24)],
            'photos.*' => ['uuid'],
            'show_on_invite' => ['boolean'],
        ]);

        $cards->update($cards->forEvent($event), $data + ['photos' => $data['photos'] ?? []]);

        return back()->with('status', trans('keepsakes.thank_you.saved'));
    }

    public function publish(Request $request, Event $event, ThankYouCards $cards): RedirectResponse
    {
        Gate::authorize('update', $event);
        abort_unless($event->invitation, 404);
        $publish = $request->boolean('publish', true);

        $cards->publish($cards->forEvent($event), $publish);

        return back()->with('status', trans($publish ? 'keepsakes.thank_you.published' : 'keepsakes.thank_you.unpublished'));
    }
}
