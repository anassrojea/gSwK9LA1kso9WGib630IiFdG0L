<?php

namespace App\Http\Controllers\Invite;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Rose;
use App\Services\InvitationConfig\InvitationConfig;
use Illuminate\Http\JsonResponse;

class RoseController extends Controller
{
    /**
     * POST /e/{slug}/rose: "send a rose" to the couple; returns the new count.
     */
    public function store(string $slug): JsonResponse
    {
        $invitation = Invitation::query()->with('event')->where('slug', $slug)->first();

        abort_if(
            ! $invitation?->published_at
            || $invitation->event->isArchived()
            || ! InvitationConfig::section($invitation->renderConfig(), 'send_rose'),
            404,
        );

        return response()->json(['count' => Rose::send($invitation->event)]);
    }
}
