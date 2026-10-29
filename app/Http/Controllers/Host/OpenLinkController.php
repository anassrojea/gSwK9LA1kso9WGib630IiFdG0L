<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Digits;
use App\Services\OpenLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The host's open-link settings as JSON, for the wizard's review step and
 * the event dashboard (docs/GUEST_LINKS.md). Routes: auth + can:update,event.
 */
class OpenLinkController extends Controller
{
    public function __construct(private OpenLinks $links) {}

    /**
     * GET /events/{event}/open-link
     */
    public function show(Event $event): JsonResponse
    {
        return response()->json($this->links->payload($event));
    }

    /**
     * PUT /events/{event}/open-link {enabled?: bool, cap?: int}
     */
    public function update(Request $request, Event $event): JsonResponse
    {
        $maxCap = $this->links->maxCap($event);
        $hasLink = $event->openLink()->exists();

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'cap' => [Rule::requiredIf(! $hasLink && $request->boolean('enabled')), 'nullable', 'integer', 'min:1', 'max:'.$maxCap],
        ], [
            'cap.max' => trans('app.wizard.review.open_link.cap_max', ['max' => Digits::localize($maxCap)]),
        ]);

        // the open link is on every plan today; the check keeps it switchable per plan (Entitlements::OPEN_LINK)
        abort_if(($data['enabled'] ?? false) && ! $this->links->available($event), 403, trans('app.wizard.review.open_link.unavailable'));

        $this->links->configure($event, $data['enabled'] ?? null, isset($data['cap']) ? (int) $data['cap'] : null);

        return response()->json($this->links->payload($event));
    }
}
