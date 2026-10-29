<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Extend storage" (plan section 4.3): how long an event's photos and guest
 * data are kept (events.storage_until, plan section 20: auto-delete after the
 * storage period). Admins only, audit-logged. Paid photo cloud add-ons extend
 * it through OrderFulfillment instead.
 */
class EventStorage
{
    public const PRESETS = ['90d' => 90, '1y' => 365];

    /**
     * The new date for a preset, counted from the later of today and the current date.
     */
    public static function presetDate(Event $event, string $preset): CarbonImmutable
    {
        $base = $event->storage_until && $event->storage_until->isFuture()
            ? CarbonImmutable::parse($event->storage_until)
            : CarbonImmutable::today();

        return $base->addDays(self::PRESETS[$preset] ?? throw new \InvalidArgumentException("Unknown preset [{$preset}]."));
    }

    public function extend(Event $event, CarbonImmutable $until, User $admin, ?string $note = null): Event
    {
        if (! $admin->isAdmin()) {
            throw new AuthorizationException(trans('app.payments.errors.admins_only'));
        }
        if ($until->startOfDay()->lte(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['until' => trans('admin.events.storage_future')]);
        }

        return DB::transaction(function () use ($event, $until, $admin, $note) {
            $event = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $from = $event->storage_until?->toDateString();

            $event->forceFill(['storage_until' => $until->toDateString()])->save();

            AuditLog::record('event.storage_extended', $event, array_filter([
                'from' => $from,
                'to' => $until->toDateString(),
                'note' => $note,
            ]), $admin);

            return $event;
        });
    }
}
