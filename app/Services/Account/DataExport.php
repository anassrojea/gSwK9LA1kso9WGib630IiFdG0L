<?php

namespace App\Services\Account;

use App\Models\Event;
use App\Models\GuestbookEntry;
use App\Models\Household;
use App\Models\Media;
use App\Models\Order;
use App\Models\User;
use App\Services\PublishGate;
use Illuminate\Support\Facades\Storage;

/**
 * "Export my data" (plan section 20): everything the account holds, as one
 * JSON document: the profile, the events the user owns with their invitation
 * config, households and RSVPs, guestbook text and uploads (listed by URL,
 * not bundled), and the orders with their payments and receipts.
 *
 * Built synchronously on request: accounts are small (one JSON document,
 * no media bytes). If very large accounts appear, move build() into a
 * queued job that stores the file and emails a signed link.
 */
class DataExport
{
    public const FORMAT_VERSION = 1;

    public function __construct(private PublishGate $gate) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $disk = Storage::disk(config('filesystems.media_disk', 'public'));

        $events = Event::query()
            ->where('owner_id', $user->getKey())
            ->with(['invitation', 'households.rsvp', 'media', 'openLink', 'reminders', 'entitlements'])
            ->orderBy('created_at')
            ->get();

        $guestbook = GuestbookEntry::query()->whereIn('event_id', $events->modelKeys())->get()->groupBy('event_id');

        return [
            'format' => 'daawa-account-export',
            'version' => self::FORMAT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'phone' => $user->phone,
                'locale' => $user->locale,
                'country' => $user->country,
                'currency' => $user->currency,
                'role' => $user->role,
                'sign_in_methods' => $user->signInMethods(),
                'google_linked' => $user->google_id !== null,
                'created_at' => $user->created_at?->toIso8601String(),
                'deletion_requested_at' => $user->deletion_requested_at?->toIso8601String(),
            ],
            'events' => $events->map(fn (Event $event) => [
                'id' => $event->id,
                'title' => $event->title,
                'occasion' => $event->occasion,
                'status' => $event->status,
                'plan' => $this->gate->currentPlan($event),
                'starts_at' => $event->starts_at?->toIso8601String(),
                'timezone' => $event->timezone,
                'venue_name' => $event->venue_name,
                'address' => $event->address,
                'lat' => $event->lat,
                'lng' => $event->lng,
                'messages' => $event->messages,
                'created_at' => $event->created_at?->toIso8601String(),
                'invitation' => $event->invitation ? [
                    'template_id' => $event->invitation->template_id,
                    'slug' => $event->invitation->slug,
                    'published_at' => $event->invitation->published_at?->toIso8601String(),
                    'config_version' => $event->invitation->config_version,
                    'config' => $event->invitation->config,
                ] : null,
                'open_link' => $event->openLink ? ['enabled' => $event->openLink->enabled, 'cap' => $event->openLink->cap] : null,
                'households' => $event->households->map(fn (Household $h) => [
                    'id' => $h->id,
                    'name' => $h->display_name,
                    'phone' => $h->phone,
                    'seats_allowed' => $h->seats_allowed,
                    'section' => $h->section,
                    'tags' => $h->tags,
                    'first_opened_at' => $h->first_opened_at?->toIso8601String(),
                    'last_opened_at' => $h->last_opened_at?->toIso8601String(),
                    'open_count' => $h->open_count,
                    'rsvp' => $h->rsvp ? [
                        'attending' => $h->rsvp->attending,
                        'people_count' => $h->rsvp->people_count,
                        'kids_count' => $h->rsvp->kids_count,
                        'message' => $h->rsvp->message,
                        'extras' => $h->rsvp->extras,
                        'responded_at' => $h->rsvp->responded_at?->toIso8601String(),
                    ] : null,
                ])->values()->all(),
                'reminders' => $event->reminders->map(fn ($r) => [
                    'household_id' => $r->household_id,
                    'channel' => $r->channel,
                    'status' => $r->status,
                    'sent_at' => $r->sent_at ? (string) $r->sent_at : null,
                ])->values()->all(),
                'guestbook' => ($guestbook[$event->id] ?? collect())->map(fn ($g) => [
                    'household_id' => $g->household_id,
                    'kind' => $g->kind,
                    'text' => $g->text,
                    'media_id' => $g->media_id,
                    'created_at' => $g->created_at?->toIso8601String(),
                ])->values()->all(),
                'features' => collect($event->entitlements)->pluck('feature')->unique()->values()->all(),
                // files are listed by URL, not bundled
                'media' => $event->media->map(fn (Media $m) => [
                    'id' => $m->id,
                    'kind' => $m->kind,
                    'url' => $disk->url($m->storage_key),
                    'bytes' => $m->bytes,
                    'width' => $m->width,
                    'height' => $m->height,
                    'hidden' => $m->hidden,
                    'created_at' => $m->created_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'orders' => Order::query()
                ->where('user_id', $user->getKey())
                ->with(['payments', 'transferReceipts', 'invoices'])
                ->latest()
                ->get()
                ->map(fn (Order $o) => [
                    'code' => $o->code,
                    'event_id' => $o->event_id,
                    'status' => $o->status,
                    'method' => $o->method,
                    'gateway' => $o->gateway,
                    'items' => $o->items,
                    'total_minor' => $o->total_cents,
                    'currency' => $o->currency,
                    'requested_currency' => $o->requested_currency,
                    'created_at' => $o->created_at?->toIso8601String(),
                    'paid_at' => $o->paid_at?->toIso8601String(),
                    'refunded_at' => $o->refunded_at?->toIso8601String(),
                    'payments' => $o->payments->map(fn ($p) => [
                        'provider' => $p->provider,
                        'amount_minor' => $p->amount_cents,
                        'currency' => $p->currency,
                        'status' => $p->status,
                        'created_at' => $p->created_at?->toIso8601String(),
                    ])->values()->all(),
                    'transfer_receipts' => $o->transferReceipts->map(fn ($r) => [
                        'received_via' => $r->received_via,
                        'received_at' => $r->received_at?->toIso8601String(),
                        'note' => $r->note,
                        'decision' => $r->decision,
                        'decided_at' => $r->decided_at?->toIso8601String(),
                    ])->values()->all(),
                    // invoices, receipts and credit notes (phase 6B); the PDFs download from the order page
                    'invoices' => $o->invoices->map(fn ($i) => [
                        'number' => $i->number,
                        'kind' => $i->kind,
                        'type' => $i->type,
                        'issued_at' => $i->issued_at->toIso8601String(),
                        'total_minor' => $i->total_cents,
                        'vat_minor' => $i->vat_cents,
                        'currency' => $i->currency,
                    ])->values()->all(),
                ])->values()->all(),
        ];
    }

    public static function filename(User $user): string
    {
        return 'daawa-my-data-'.now()->format('Y-m-d').'.json';
    }
}
