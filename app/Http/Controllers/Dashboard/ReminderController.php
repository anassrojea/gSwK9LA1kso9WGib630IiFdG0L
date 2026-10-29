<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Household;
use App\Models\Reminder;
use App\Services\Dashboard\GuestBoard;
use App\Services\Dashboard\WhatsApp;
use App\Services\PublishGate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * "Remind those who didn't reply" (plan sections 8 and 14.1). There is no
 * WhatsApp API yet, so sending stays manual: the host gets one personal
 * wa.me link per guest and taps them one by one. Each guest gets a
 * reminders row (channel whatsapp, status queued), marked sent on the tap.
 */
class ReminderController extends Controller
{
    /**
     * POST /dashboard/events/{event}/reminders {audience: never|opened|both}
     */
    public function store(Request $request, Event $event, PublishGate $gate): JsonResponse
    {
        Gate::authorize('update', $event);

        // reminders go out on personal links: a Premium feature
        if (! $gate->canRemind($event)) {
            return response()->json(['message' => trans('app.checkout.reminders_premium')], 403);
        }

        $audience = $request->validate(['audience' => ['required', Rule::in(GuestBoard::AUDIENCES)]])['audience'];

        $event->loadMissing('invitation');
        $templates = WhatsApp::templates($event);
        $households = GuestBoard::audience($event, $audience)->orderBy('created_at')->orderBy('id')->get();
        $lastSent = GuestBoard::lastReminders($event);

        $reminders = DB::transaction(function () use ($event, $households) {
            // reopening the list reuses the reminders not tapped yet instead of piling up new ones
            $queued = Reminder::query()
                ->where('event_id', $event->getKey())
                ->where('channel', 'whatsapp')
                ->where('status', 'queued')
                ->whereIn('household_id', $households->modelKeys())
                ->get()
                ->keyBy('household_id');

            return $households->mapWithKeys(fn (Household $h) => [
                $h->id => $queued[$h->id] ?? Reminder::query()->create([
                    'event_id' => $event->getKey(),
                    'household_id' => $h->getKey(),
                    'channel' => 'whatsapp',
                    'status' => 'queued',
                ]),
            ]);
        });

        return response()->json([
            'audience' => $audience,
            'guests' => $households->map(fn (Household $h) => [
                'reminder_id' => $reminders[$h->id]->id,
                'household_id' => $h->id,
                'name' => $h->display_name,
                'phone' => $h->phone,
                'opened' => $h->first_opened_at !== null,
                'last_opened_at' => $h->last_opened_at?->toIso8601String(),
                'last_reminded_at' => isset($lastSent[$h->id]) ? CarbonImmutable::parse($lastSent[$h->id])->toIso8601String() : null,
                'whatsapp_url' => WhatsApp::url($h->phone, WhatsApp::message($event, $h, 'reminder', $templates)),
            ])->values(),
        ]);
    }

    /**
     * POST /dashboard/reminders/{reminder}/sent: the host tapped the link.
     */
    public function sent(Reminder $reminder): JsonResponse
    {
        Gate::authorize('update', $reminder->event);

        if ($reminder->status !== 'sent') {
            $reminder->update(['status' => 'sent', 'sent_at' => now()]);
        }

        return response()->json([
            'reminder_id' => $reminder->id,
            'household_id' => $reminder->household_id,
            'status' => $reminder->status,
            'sent_at' => $reminder->sent_at?->toIso8601String(),
        ]);
    }
}
