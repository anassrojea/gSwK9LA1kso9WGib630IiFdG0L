<?php

namespace App\Services\Storage;

use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\Event;
use App\Models\Media;
use App\Notifications\StorageExpiring;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The end of an event's storage period (plan sections 11 and 20), run daily by
 * events:expire-storage. For each event with events.storage_until:
 *
 *   1. warn    14 and 3 days before (config retention.warn_days), once per storage date
 *              (events.storage_warnings), only when there is media to lose
 *   2. purge   the day after storage_until: the media files (uploads, gallery, cover, guest photos)
 *              and their rows, and the link preview images; the event becomes "ended".
 *              RSVPs, households and the invitation text stay; media:<uuid> refs in the
 *              config now resolve to nothing (MediaUrl returns null for a missing row).
 *   3. archive retention.archive_grace_days after storage_until: status "archived", so /i/* is a 404
 *
 * A photo cloud bought "forever" (an entitlement with no end) keeps everything.
 * Every step is audit-logged; with $dryRun nothing changes and nothing is sent.
 */
class StorageExpiry
{
    /**
     * @return array{warned: list<array{event: string, days: int}>, purged: list<array{event: string, files: int, media: int}>, archived: list<string>}
     */
    public function run(?CarbonImmutable $today = null, bool $dryRun = false): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $report = ['warned' => [], 'purged' => [], 'archived' => []];

        $horizon = $today->addDays(max(config('retention.warn_days', [14, 3]) ?: [0]));

        Event::query()
            ->whereNotNull('storage_until')
            ->where('status', '!=', 'archived')
            ->whereDate('storage_until', '<=', $horizon->toDateString())
            ->with('owner')
            ->chunkById(200, function ($events) use ($today, $dryRun, &$report) {
                foreach ($events as $event) {
                    if ($this->keptForever($event)) {
                        continue;
                    }

                    $until = CarbonImmutable::parse($event->storage_until->toDateString())->startOfDay();

                    if ($until->gte($today)) {
                        if ($days = $this->warn($event, $until, $today, $dryRun)) {
                            $report['warned'][] = ['event' => $event->id, 'days' => $days];
                        }

                        continue;
                    }

                    if ($event->media_purged_at === null) {
                        $report['purged'][] = ['event' => $event->id, ...$this->purge($event, $dryRun)];
                    }

                    if ($today->gt($until->addDays((int) config('retention.archive_grace_days', 30)))) {
                        $this->archive($event, $dryRun);
                        $report['archived'][] = $event->id;
                    }
                }
            });

        return $report;
    }

    /**
     * The most urgent warning not yet sent for this storage date, or null.
     *
     * @return ?int the warning (days before) that was sent
     */
    public function warn(Event $event, CarbonImmutable $until, CarbonImmutable $today, bool $dryRun = false): ?int
    {
        $left = (int) $today->diffInDays($until);
        $due = collect(config('retention.warn_days', [14, 3]))->map(fn ($d) => (int) $d)->filter(fn (int $d) => $left <= $d)->sort()->values();
        if ($due->isEmpty()) {
            return null;
        }

        $markers = $event->storage_warnings ?? [];
        $sent = ($markers['until'] ?? null) === $until->toDateString() ? ($markers['sent'] ?? []) : [];
        $days = $due->first();

        if (isset($sent[(string) $days]) || ! $this->hasMedia($event) || ! filled($event->owner?->email)) {
            return null;
        }

        if ($dryRun) {
            return $days;
        }

        // this warning and every earlier one it makes pointless (a late run never sends the 14-day one after the 3-day one)
        foreach ($due as $d) {
            $sent[(string) $d] ??= now()->toIso8601String();
        }
        $event->forceFill(['storage_warnings' => ['until' => $until->toDateString(), 'sent' => $sent]])->save();

        $event->owner->notify(new StorageExpiring($event, $left));
        AuditLog::record('event.storage_warning', $event, ['days' => $days, 'left' => $left, 'until' => $until->toDateString()]);

        return $days;
    }

    /**
     * @return array{files: int, media: int}
     */
    public function purge(Event $event, bool $dryRun = false): array
    {
        $disk = $this->disk();
        $media = Media::query()->where('event_id', $event->getKey())->get(['id', 'storage_key', 'thumb_key']);

        $files = collect($media)
            ->flatMap(fn (Media $m) => array_filter([$m->storage_key, $m->thumb_key]))
            ->merge($disk->allFiles('events/'.$event->getKey()))
            ->merge($disk->allFiles('og/'.$event->getKey()))
            ->unique()
            ->values();

        $counts = ['files' => $files->count(), 'media' => $media->count()];
        if ($dryRun) {
            return $counts;
        }

        DB::transaction(function () use ($event, $counts) {
            Media::query()->where('event_id', $event->getKey())->delete();

            $event->forceFill([
                'media_purged_at' => now(),
                'status' => $event->status === 'archived' ? 'archived' : 'ended',
            ])->save();

            AuditLog::record('event.media_purged', $event, $counts + ['until' => $event->storage_until?->toDateString()]);
        });

        // files after the rows: a failed delete leaves an orphan file, never a row pointing at nothing
        $disk->delete($files->all());
        $disk->deleteDirectory('events/'.$event->getKey());
        $disk->deleteDirectory('og/'.$event->getKey());

        return $counts;
    }

    public function archive(Event $event, bool $dryRun = false): void
    {
        if ($dryRun || $event->isArchived()) {
            return;
        }

        $event->forceFill(['status' => 'archived'])->save();
        AuditLog::record('event.archived', $event, [
            'reason' => 'storage_expired',
            'until' => $event->storage_until?->toDateString(),
            'grace_days' => (int) config('retention.archive_grace_days', 30),
        ]);
    }

    private function hasMedia(Event $event): bool
    {
        return Media::query()->where('event_id', $event->getKey())->exists();
    }

    /**
     * Photo cloud "forever" (an active photo_cloud entitlement without an end).
     */
    private function keptForever(Event $event): bool
    {
        return Entitlement::query()
            ->where('event_id', $event->getKey())
            ->where('feature', 'photo_cloud')
            ->whereNull('until')
            ->exists();
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.media_disk', 'public'));
    }
}
