<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateInvitationConfigRequest;
use App\Models\Event;
use Illuminate\Http\JsonResponse;

class InvitationConfigController extends Controller
{
    /**
     * PATCH /events/{event}/config: wizard autosave (plan section 18). The
     * whole document is sent; the request validates it against the schema.
     */
    public function update(UpdateInvitationConfigRequest $request, Event $event): JsonResponse
    {
        $invitation = $event->invitation()->firstOrNew();
        $invitation->config = $request->validated('config');
        $invitation->save();

        // TODO(edge cache): purge /i/* for this event through the Cloudflare API (plan section 6.4)

        return response()->json([
            'config_version' => $invitation->config_version,
            'updated_at' => $invitation->updated_at?->toIso8601String(),
        ]);
    }
}
