<?php

namespace App\Http\Controllers\Door;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\SeatingController;
use App\Models\DoorPass;
use App\Models\Event;
use App\Services\Door\DoorPasses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET/POST /door/{event}/pass/{pass} (signed): door staff without an account
 * type the 6-digit PIN the host gave them (App\Services\Door\DoorPasses).
 */
class DoorPassController extends Controller
{
    public function __construct(private DoorPasses $passes) {}

    public function show(Request $request, Event $event, DoorPass $pass): Response|RedirectResponse
    {
        abort_unless($pass->event_id === $event->getKey(), 404);

        if ($this->passes->fromSession($request, $event)?->is($pass)) {
            return redirect()->route('door.show', $event);
        }

        return Inertia::render('door/Pin', [
            'event' => ['id' => $event->id, 'title' => $event->title],
            'pass' => ['label' => $pass->label, 'active' => $pass->isActive()],
            'translations' => SeatingController::translations(),
        ]);
    }

    public function unlock(Request $request, Event $event, DoorPass $pass): RedirectResponse
    {
        abort_unless($pass->event_id === $event->getKey(), 404);

        $data = $request->validate(['pin' => ['required', 'string', 'max:12']]);
        $this->passes->unlock($request, $pass, preg_replace('/\D/', '', $data['pin']));

        return redirect()->route('door.show', $event);
    }
}
