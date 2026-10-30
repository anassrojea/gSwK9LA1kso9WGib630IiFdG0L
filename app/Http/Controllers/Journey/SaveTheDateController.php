<?php

namespace App\Http\Controllers\Journey;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Journey\SaveTheDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * POST /events/{event}/save-the-date/upgrade {occasion}: the full invitation
 * for a save-the-date (App\Services\Journey\SaveTheDate::upgrade), then the
 * wizard's design step of the new draft.
 */
class SaveTheDateController extends Controller
{
    public function upgrade(Request $request, Event $event, SaveTheDate $saveTheDate): RedirectResponse
    {
        Gate::authorize('update', $event);
        $data = $request->validate(['occasion' => ['required', 'string', 'max:32']]);

        $invitation = $saveTheDate->upgrade($event, $request->user(), $data['occasion']);

        return redirect()->route('create.step', [$invitation, 'design'])->with('status', trans('journey.save_the_date.upgraded'));
    }
}
