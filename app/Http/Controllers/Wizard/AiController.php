<?php

namespace App\Http\Controllers\Wizard;

use App\Http\Controllers\Controller;
use App\Jobs\AiCleanGuestList;
use App\Jobs\AiWritePoem;
use App\Jobs\AiWriteStory;
use App\Models\AiGeneration;
use App\Models\Event;
use App\Services\Ai\AiPrompts;
use App\Services\Digits;
use App\Services\Entitlements;
use App\Services\InvitationConfig\InvitationConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * AI writing for the host (plan sections 14.3 and 18), each call a queued job
 * the page polls:
 *
 *   POST /events/{event}/ai/story      {how_met, moment, meaning, tone, language, dialect}
 *   POST /events/{event}/ai/poem       {tone, language, dialect}   (add-on "ai_poem")
 *   POST /events/{event}/guests/clean  {text}
 *   GET  /ai/generations/{generation}  status and the validated suggestion
 *
 * Signed-in hosts only (throttled per user and per event, plus a monthly
 * allowance per plan). Results are suggestions: the wizard shows them for the
 * host to accept or edit, and nothing is saved to the invitation or the guest
 * list from here.
 */
class AiController extends Controller
{
    public const POEM = 'ai_poem';

    public function __construct(private Entitlements $entitlements) {}

    public function story(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('update', $event);
        $data = $request->validate([
            'how_met' => ['nullable', 'string', 'max:1500', 'required_without_all:moment,meaning'],
            'moment' => ['nullable', 'string', 'max:1500'],
            'meaning' => ['nullable', 'string', 'max:1500'],
            ...$this->styleRules(),
        ], ['how_met.required_without_all' => __('ai.story.need_answer')]);

        return $this->start($request, $event, 'story', [
            ...$this->eventFacts($event),
            ...$this->style($data, $event),
            'how_met' => trim((string) ($data['how_met'] ?? '')),
            'moment' => trim((string) ($data['moment'] ?? '')),
            'meaning' => trim((string) ($data['meaning'] ?? '')),
        ], AiWriteStory::class);
    }

    public function poem(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('update', $event);
        if (! $this->entitlements->hasFeature($event, self::POEM)) {
            return response()->json(['message' => __('ai.poem.locked'), 'reason' => 'addon'], 403);
        }
        $data = $request->validate($this->styleRules());

        return $this->start($request, $event, 'poem', [...$this->eventFacts($event), ...$this->style($data, $event)], AiWritePoem::class);
    }

    public function clean(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('update', $event);
        $data = $request->validate(['text' => ['required', 'string', 'max:20000']]);

        return $this->start($request, $event, 'clean', ['text' => trim($data['text'])], AiCleanGuestList::class);
    }

    public function show(Request $request, AiGeneration $generation): JsonResponse
    {
        abort_unless($generation->event && $request->user()->can('update', $generation->event), 404);

        return response()->json(static::payload($generation));
    }

    public static function payload(AiGeneration $generation): array
    {
        return [
            'id' => $generation->id,
            'kind' => $generation->kind,
            'status' => $generation->status,
            'result' => $generation->status === 'done' ? $generation->result : null,
            'error' => $generation->error,
            'message' => $generation->status === 'failed' ? __('ai.errors.'.($generation->error ?: 'unavailable')) : null,
            'poll' => route('ai.generations.show', $generation, false),
        ];
    }

    /**
     * Monthly allowance per event and kind (config ai.monthly). Calls that never
     * reached the model (busy, unavailable) do not count.
     */
    public function remaining(Event $event, string $kind): ?int
    {
        $plan = $event->plan_id ?? $event->organization?->plan_id ?? Entitlements::FALLBACK_PLAN;
        $limit = config("ai.monthly.{$plan}.{$kind}", config("ai.monthly.free.{$kind}"));
        if ($limit === null) {
            return null;
        }

        $used = AiGeneration::query()
            ->where('event_id', $event->getKey())
            ->where('kind', $kind)
            ->where('created_at', '>=', now()->startOfMonth())
            ->where(fn ($q) => $q->whereNull('error')->orWhereNotIn('error', ['busy', 'unavailable']))
            ->count();

        return max(0, (int) $limit - $used);
    }

    /**
     * @param  class-string  $job
     */
    private function start(Request $request, Event $event, string $kind, array $input, string $job): JsonResponse
    {
        if ($this->remaining($event, $kind) === 0) {
            return response()->json(['message' => __('ai.errors.monthly'), 'reason' => 'monthly'], 429);
        }

        $generation = AiGeneration::query()->create([
            'event_id' => $event->getKey(),
            'user_id' => $request->user()->getKey(),
            'kind' => $kind,
            'input' => $input,
            'model' => (string) config("ai.models.{$kind}"),
            'status' => 'queued',
        ]);

        $job::dispatch($generation->getKey());

        return response()->json(static::payload($generation->refresh()), 202);
    }

    private function styleRules(): array
    {
        return [
            'tone' => ['nullable', Rule::in(AiPrompts::TONES)],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'dialect' => ['nullable', Rule::in(AiPrompts::DIALECTS)],
        ];
    }

    private function style(array $data, Event $event): array
    {
        $language = $data['language'] ?? ($event->invitation?->config['locale'] ?? 'ar');

        return [
            'language' => $language,
            'dialect' => $language === 'ar' ? ($data['dialect'] ?? 'fusha') : null,
            'tone' => $data['tone'] ?? 'warm',
        ];
    }

    /** The names and occasion from the invitation: the model is told to use no others. */
    private function eventFacts(Event $event): array
    {
        $config = $event->invitation?->config ?? [];
        $names = $config ? InvitationConfig::section($config, 'names') : null;
        $date = $config ? InvitationConfig::section($config, 'date') : null;

        return [
            'occasion' => $event->occasion,
            'names' => array_filter(['first' => $names['first'] ?? null, 'second' => $names['second'] ?? null]),
            'year' => $date ? Digits::toWestern(substr((string) $date['start'], 0, 4)) : null,
        ];
    }
}
