<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateInvitationConfigRequest;
use App\Models\Event;
use App\Services\Wizard\EventSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InvitationConfigController extends Controller
{
    /**
     * PATCH /events/{event}/config: wizard autosave (plan section 18). The
     * whole document is sent; the request validates it against the schema.
     * The event columns that mirror the config (title, starts_at, venue) are
     * kept in sync in the same transaction.
     */
    public function update(UpdateInvitationConfigRequest $request, Event $event): JsonResponse
    {
        $invitation = $event->invitation()->firstOrNew();
        $invitation->config = $request->validated('config');
        if ($request->has('template_id')) {
            $invitation->template_id = $request->validated('template_id');
        }

        DB::transaction(function () use ($invitation, $event) {
            $invitation->save();
            EventSync::apply($event, $invitation->config)->save();
        });

        // TODO(edge cache): purge /i/* for this event through the Cloudflare API (plan section 6.4)

        return response()->json([
            'config_version' => $invitation->config_version,
            'updated_at' => $invitation->updated_at?->toIso8601String(),
            'title' => $event->title,
        ]);
    }
}
