<?php

namespace App\Services\Ai;

/**
 * One model response: why it stopped, the decoded JSON (structured output),
 * the raw text and the token usage for cost tracking.
 */
final class AiResult
{
    public function __construct(
        public readonly string $model,
        public readonly ?string $stopReason,
        public readonly string $text,
        public readonly ?array $data,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
    ) {}

    /** Usable only when the model finished normally: a refusal or a cut-off answer is never applied. */
    public function complete(): bool
    {
        return in_array($this->stopReason, ['end_turn', 'stop_sequence'], true) && $this->data !== null;
    }

    /**
     * USD cents, rounded up to whole cents, from config ai.prices
     * (cents per million tokens [input, output]).
     */
    public static function costCents(string $model, int $inputTokens, int $outputTokens): int
    {
        [$in, $out] = config("ai.prices.{$model}") ?? [0, 0];
        $microCents = $inputTokens * $in + $outputTokens * $out;

        return intdiv($microCents + 999_999, 1_000_000);
    }

    public function cents(): int
    {
        return self::costCents($this->model, $this->inputTokens, $this->outputTokens);
    }
}
