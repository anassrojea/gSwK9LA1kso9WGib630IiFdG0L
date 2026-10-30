<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsAiGeneration;
use App\Services\Ai\AiOutput;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * AI writing (plan section 14.3): the story (حكايتنا) from the host's three answers.
 * The validated result waits in ai_generations.result for the host to accept or edit.
 */
class AiWriteStory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsAiGeneration;

    protected function validate(?array $data): ?array
    {
        return AiOutput::story($data);
    }
}
