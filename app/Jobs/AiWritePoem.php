<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsAiGeneration;
use App\Services\Ai\AiOutput;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * AI writing (plan section 14.3): a short poem (أبيات) with the couple's names, for an intro_line verse section.
 * The validated result waits in ai_generations.result for the host to accept or edit.
 */
class AiWritePoem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsAiGeneration;

    protected function validate(?array $data): ?array
    {
        return AiOutput::poem($data);
    }
}
