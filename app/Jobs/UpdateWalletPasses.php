<?php

namespace App\Jobs;

use App\Models\WalletPass;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Issued passes are out of date: the event's time or hall changed, or a
 * family's table did (plan section 10, "updates").
 *
 * STUB: it only logs which passes would be refreshed. The next step is
 * Apple's pass web service (webServiceURL + device registrations, then an
 * APNs push so devices fetch passesUpdatedSince) and Google's REST API
 * (PATCH eventTicketObject / eventTicketClass). Serials and
 * authenticationTokens are already stored in wallet_passes.
 */
class UpdateWalletPasses implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ?string $eventId = null, public ?string $householdId = null, public string $reason = '') {}

    public function handle(): void
    {
        $passes = WalletPass::query()
            ->when($this->householdId, fn ($q) => $q->where('household_id', $this->householdId))
            ->when($this->eventId && ! $this->householdId, fn ($q) => $q->whereHas('household', fn ($h) => $h->where('event_id', $this->eventId)))
            ->get(['id', 'household_id', 'platform', 'serial']);

        if ($passes->isEmpty()) {
            return;
        }

        Log::info('wallet: passes need an update (push not implemented yet)', [
            'event' => $this->eventId,
            'household' => $this->householdId,
            'reason' => $this->reason,
            'passes' => $passes->map(fn (WalletPass $p) => $p->platform.':'.$p->serial)->all(),
        ]);
    }
}
