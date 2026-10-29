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
     * of people can never exceed the seats the host allocated. Open-link
     * tokens are handed to OpenLinkController.
     */
    public function store(Request $request, string $token, Entitlements $entitlements): JsonResponse
    {
        // guest routes have no session to flash errors into: always answer validation in JSON
        $request->headers->set('Accept', 'application/json');

        $household = $this->findHousehold($token);
        if (! $household) {
            return app(OpenLinkController::class)->rsvp($request, $token, $entitlements);
        }

        [$household, $invitation, $config] = $this->resolve($token, $household);

        $section = static::openSection($config);

        $data = static::validateReply($request, $section, $household->seats_allowed,
            trans_choice('invite.rsvp.too_many', $household->seats_allowed, ['count' => $household->seats_allowed]));

        $rsvp = DB::transaction(function () use ($household, $data) {
            // serialize concurrent replies for the same household
            Household::query()->whereKey($household->getKey())->lockForUpdate()->first();

            return static::saveReply($household, $data);
        });

        $household->setRelation('rsvp', $rsvp);

        RsvpSubmitted::dispatch($rsvp);

        return response()->json(InvitationController::statePayload($household, $invitation, $config, $entitlements));
    }

    /**
     * The invitation's RSVP section: a 404 when it has none, a 422 after the cutoff.
     */
    public static function openSection(array $config): array
    {
        $section = InvitationConfig::section($config, 'rsvp');
        abort_if(! $section || ($section['hidden'] ?? false), 404);

        if (! static::isOpen($section)) {
            throw ValidationException::withMessages(['attending' => __('invite.rsvp.closed')]);
        }

        return $section;
    }

    /**
     * The reply fields, checked against the RSVP section's options. Shared by
     * personal links and the open link (which adds the guest's name).
     *
     * @param  array<string, mixed>  $extraRules
     */
    public static function validateReply(Request $request, array $section, int $maxPeople, string $tooMany, array $extraRules = []): array
    {
        return $request->validate([
            'attending' => ['required', 'boolean'],
            'people_count' => [Rule::excludeIf(! $request->boolean('attending')), 'required', 'integer', 'min:1', 'max:'.$maxPeople],
            'kids_count' => [Rule::excludeIf(! $request->boolean('attending')), Rule::prohibitedIf(! ($section['askKids'] ?? false)), 'nullable', 'integer', 'min:0', 'lte:people_count'],
            'meal' => [Rule::excludeIf(! $request->boolean('attending')), Rule::prohibitedIf(empty($section['mealOptions'])), 'nullable', Rule::in($section['mealOptions'] ?? [])],
            'message' => [Rule::prohibitedIf(! ($section['askMessage'] ?? false)), 'nullable', 'string', 'max:500'],
            'allergies' => [Rule::prohibitedIf(! ($section['askAllergies'] ?? false)), 'nullable', 'string', 'max:300'],
            ...$extraRules,
        ], [
            'people_count.max' => $tooMany,
        ]);
    }

    /**
     * Create or replace the household's reply. Callers hold the lock that
     * serializes replies (the household row, or the open link's row).
     */
    public static function saveReply(Household $household, array $data): Rsvp
    {
        $attending = (bool) $data['attending'];

        return Rsvp::query()->updateOrCreate(['household_id' => $household->getKey()], [
            'attending' => $attending,
            'people_count' => $attending ? (int) $data['people_count'] : 0,
            'kids_count' => $attending ? (int) ($data['kids_count'] ?? 0) : 0,
            'message' => $data['message'] ?? null,
            'extras' => array_filter([
                'allergies' => $data['allergies'] ?? null,
                'meal' => $attending ? ($data['meal'] ?? null) : null,
            ]) ?: null,
            'responded_at' => now(),
        ]);
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
