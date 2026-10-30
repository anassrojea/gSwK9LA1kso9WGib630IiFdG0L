<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsAiGeneration;
use App\Services\Ai\AiOutput;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * AI writing (plan section 14.3): families, seats and phones from a messy pasted guest list.
 * The validated result waits in ai_generations.result for the host to accept or edit.
 */
class AiCleanGuestList implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsAiGeneration;

    protected function validate(?array $data): ?array
    {
        return AiOutput::families($data);
    }
}
