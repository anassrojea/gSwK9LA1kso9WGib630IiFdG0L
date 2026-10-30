<?php

namespace App\Jobs\Concerns;

use App\Models\AiGeneration;
use App\Services\Ai\AiResult;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\AiWriter;
use Illuminate\Support\Facades\Log;

/**
 * One AI call for an ai_generations row (queued → running → done | failed):
 * stop reasons are checked before the content is used, the output is
 * validated by the job, and every call is recorded with its tokens and cost.
 * The result is only a suggestion: nothing is applied to the event here.
 */
trait RunsAiGeneration
{
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $generationId) {}

    /** The validated result, or null when the model gave nothing usable. */
    abstract protected function validate(?array $data): ?array;

    public function handle(AiWriter $writer): void
    {
        $generation = AiGeneration::query()->find($this->generationId);
        if (! $generation || $generation->finished()) {
            return;
        }

        $generation->update(['status' => 'running']);
        $input = $generation->input;
        unset($input['usage']);

        try {
            $result = $writer->write($generation->kind, $input);
        } catch (AiUnavailable $e) {
            $generation->update(['status' => 'failed', 'error' => $e->reason]);

            return;
        }

        $this->record($generation, $result);

        $error = match (true) {
            $result->stopReason === 'refusal' => 'refusal',
            $result->stopReason === 'max_tokens' => 'max_tokens',
            ! $result->complete() => 'invalid',
            default => null,
        };
        $validated = $error === null ? $this->validate($result->data) : null;

        if ($validated === null) {
            $generation->update(['status' => 'failed', 'error' => $error ?? 'empty']);
            Log::info('ai: generation not usable', ['id' => $generation->id, 'kind' => $generation->kind, 'stop_reason' => $result->stopReason]);

            return;
        }

        $generation->update(['status' => 'done', 'result' => $validated, 'error' => null]);
    }

    private function record(AiGeneration $generation, AiResult $result): void
    {
        $generation->forceFill([
            'model' => $result->model,
            'output' => $result->text,
            'cost_cents' => $result->cents(),
            'input' => [...$generation->input, 'usage' => [
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'cache_read_input_tokens' => $result->cacheReadTokens,
                'cache_creation_input_tokens' => $result->cacheWriteTokens,
                'stop_reason' => $result->stopReason,
            ]],
        ])->save();
    }

    public function failed(?\Throwable $e): void
    {
        AiGeneration::query()->whereKey($this->generationId)->whereNotIn('status', ['done', 'failed'])
            ->update(['status' => 'failed', 'error' => 'unavailable']);
    }
}
