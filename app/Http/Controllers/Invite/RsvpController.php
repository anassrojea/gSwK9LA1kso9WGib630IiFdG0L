<?php

namespace App\Http\Controllers\Invite;

use App\Events\RsvpSubmitted;
use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\Rsvp;
use App\Services\Entitlements;
use App\Services\InvitationConfig\InvitationConfig;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RsvpController extends Controller
{
    use ResolvesHousehold;

    /**
     * POST /i/{token}/rsvp: create or change the household's reply. The number
     * of people can never exceed the seats the host allocated.
     */
    public function store(Request $request, string $token, Entitlements $entitlements): JsonResponse
    {
        // guest routes have no session to flash errors into: always answer validation in JSON
        $request->headers->set('Accept', 'application/json');

        [$household, $invitation, $config] = $this->resolve($token);

        $section = InvitationConfig::section($config, 'rsvp');
        abort_if(! $section || ($section['hidden'] ?? false), 404);

        if (! static::isOpen($section)) {
            throw ValidationException::withMessages(['attending' => __('invite.rsvp.closed')]);
        }

        $data = $request->validate([
            'attending' => ['required', 'boolean'],
            'people_count' => [Rule::requiredIf($request->boolean('attending')), 'nullable', 'integer', 'min:1', 'max:'.$household->seats_allowed],
            'kids_count' => ['nullable', 'integer', 'min:0', 'lte:people_count'],
            'message' => [Rule::prohibitedIf(! ($section['askMessage'] ?? false)), 'nullable', 'string', 'max:500'],
            'allergies' => [Rule::prohibitedIf(! ($section['askAllergies'] ?? false)), 'nullable', 'string', 'max:300'],
        ], [
            'people_count.max' => trans_choice('invite.rsvp.too_many', $household->seats_allowed, ['count' => $household->seats_allowed]),
        ]);

        $attending = (bool) $data['attending'];

        $rsvp = DB::transaction(function () use ($household, $data, $attending) {
            // serialize concurrent replies for the same household
            Household::query()->whereKey($household->getKey())->lockForUpdate()->first();

            return Rsvp::query()->updateOrCreate(['household_id' => $household->getKey()], [
                'attending' => $attending,
                'people_count' => $attending ? (int) $data['people_count'] : 0,
                'kids_count' => $attending ? (int) ($data['kids_count'] ?? 0) : 0,
                'message' => $data['message'] ?? null,
                'extras' => array_filter(['allergies' => $data['allergies'] ?? null]) ?: null,
                'responded_at' => now(),
            ]);
        });

        $household->setRelation('rsvp', $rsvp);

        RsvpSubmitted::dispatch($rsvp);

        return response()->json(InvitationController::statePayload($household, $invitation, $config, $entitlements));
    }

    /**
     * Replies can be changed until the host's cutoff (plan section 8).
     */
    public static function isOpen(?array $section): bool
    {
        if (! $section || ($section['hidden'] ?? false)) {
            return false;
        }

        return empty($section['cutoff']) || CarbonImmutable::parse($section['cutoff'])->isFuture();
    }
}
