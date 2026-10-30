<?php

namespace App\Services\Keepsakes;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Media;
use App\Models\Order;
use App\Models\PhotoBook;
use App\Models\User;
use App\Services\Payments\CheckoutService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The printed photo book (phase 8D, plan section 14.5). No print partner yet:
 * the host designs and orders it here, the admin downloads the print-ready
 * PDF in Filament, prints, ships and moves the status along.
 *
 *   - photos: chosen by the host, or auto-picked (spread over the evening by
 *     upload time; hidden and unapproved photos never go in)
 *   - layout: 20 or 40 pages, A4 landscape or 21×21 cm; page 1 is the cover,
 *     the last page the back cover, and the pages between alternate one photo
 *     (full bleed) and two photos (PhotoBooks::layout)
 *   - ordering: the photo_book_20 / photo_book_40 add-on through the normal
 *     checkout (quantity = copies), with the shipping address on the book;
 *     PhotoBookItem moves it to "pending" when the order is paid
 */
class PhotoBooks
{
    public function __construct(private ThankYouCards $cards) {}

    public function current(Event $event): ?PhotoBook
    {
        return PhotoBook::query()->where('event_id', $event->getKey())->latest()->first();
    }

    /**
     * The book being prepared: the latest one not ordered yet, or a new draft.
     */
    public function draft(Event $event, User $user): PhotoBook
    {
        $book = PhotoBook::query()->where('event_id', $event->getKey())->where('status', 'draft')->whereNull('order_id')->latest()->first();

        return $book ?? PhotoBook::query()->create([
            'event_id' => $event->getKey(),
            'user_id' => $user->getKey(),
            'title' => mb_substr((string) $event->title, 0, 80) ?: 'Daawa',
            'photo_ids' => [],
            'ship_name' => $user->name,
            'ship_phone' => $user->phone,
            'ship_country' => $user->country,
        ]);
    }

    /**
     * @return Collection<int, Media>
     */
    public function photos(Event $event): Collection
    {
        return $this->cards->photos($event);
    }

    /** How many photos a layout holds: the cover, then one and two per page in turn. */
    public static function capacity(int $pages): int
    {
        $interior = max(0, $pages - 2);

        return 1 + intdiv($interior + 1, 2) + 2 * intdiv($interior, 2);
    }

    /**
     * The photos that go in, in order: the host's choice, or the auto-pick.
     *
     * @return Collection<int, Media>
     */
    public function chosen(PhotoBook $book): Collection
    {
        $photos = $this->photos($book->event);
        if ($book->auto_pick || ! $book->photo_ids) {
            return ThankYouCards::spread($photos, static::capacity($book->pages));
        }

        $byId = $photos->keyBy('id');

        return collect($book->photo_ids)->map(fn ($id) => $byId->get($id))->filter()->take(static::capacity($book->pages))->values();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PhotoBook $book, array $data): PhotoBook
    {
        if ($book->status !== 'draft' || $book->order_id) {
            throw ValidationException::withMessages(['book' => trans('keepsakes.photo_book.errors.ordered')]);
        }

        if (array_key_exists('photo_ids', $data)) {
            $visible = $this->photos($book->event)->pluck('id')->all();
            $data['photo_ids'] = array_values(array_unique(array_intersect((array) $data['photo_ids'], $visible)));
            if (count($data['photo_ids']) > static::capacity((int) ($data['pages'] ?? $book->pages))) {
                throw ValidationException::withMessages(['photo_ids' => trans('keepsakes.photo_book.errors.too_many', ['max' => static::capacity((int) ($data['pages'] ?? $book->pages))])]);
            }
        }

        $book->fill(array_intersect_key($data, array_flip([
            'title', 'subtitle', 'size', 'pages', 'auto_pick', 'photo_ids', 'copies',
            'ship_name', 'ship_country', 'ship_city', 'ship_address', 'ship_phone',
        ])));
        // a changed book needs a new PDF
        if ($book->isDirty(['title', 'subtitle', 'size', 'pages', 'auto_pick', 'photo_ids'])) {
            $book->pdf_key = null;
            $book->pdf_pages = null;
            $book->pdf_at = null;
        }
        $book->save();

        return $book;
    }

    /**
     * The pages of the book, each with its photos.
     *
     * @param  Collection<int, Media>  $photos
     * @return list<array{kind: 'cover'|'single'|'double'|'text'|'back', photos: list<Media>}>
     */
    public static function layout(int $pages, Collection $photos): array
    {
        $queue = $photos->values()->all();
        $interior = max(0, $pages - 2);
        // one photo per page while photos are short; two per page once there are enough for every page
        $spare = max(0, count($queue) - 1 - $interior);
        $doubles = min(intdiv($interior, 2), $spare);

        $layout = [['kind' => 'cover', 'photos' => array_splice($queue, 0, 1)]];
        $doubled = 0;
        for ($page = 0; $page < $interior; $page++) {
            $double = $page % 2 === 1 && $doubled < $doubles;
            $take = array_splice($queue, 0, $double ? 2 : 1);
            if ($double && count($take) === 2) {
                $doubled++;
            }
            $layout[] = ['kind' => $take === [] ? 'text' : (count($take) === 2 ? 'double' : 'single'), 'photos' => $take];
        }
        $layout[] = ['kind' => 'back', 'photos' => []];

        return $layout;
    }

    /**
     * Order the book: the shipping address is required, then the normal checkout.
     */
    public function order(PhotoBook $book, User $user, string $currency, string $gateway, ?string $country): Order
    {
        $missing = array_filter(['ship_name', 'ship_country', 'ship_city', 'ship_address', 'ship_phone'], fn (string $f) => blank($book->{$f}));
        if ($missing !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_values($missing), trans('keepsakes.photo_book.errors.address')));
        }
        if ($book->status !== 'draft') {
            throw ValidationException::withMessages(['book' => trans('keepsakes.photo_book.errors.ordered')]);
        }
        if ($this->chosen($book)->isEmpty()) {
            throw ValidationException::withMessages(['photo_ids' => trans('keepsakes.photo_book.errors.no_photos')]);
        }

        $order = app(CheckoutService::class)->place($user, $book->event, null, [['id' => $book->addonId(), 'quantity' => max(1, $book->copies)]], $currency, $gateway, $country);
        $book->update(['order_id' => $order->getKey()]);
        AuditLog::record('photo_book.ordered', $book, ['order' => $order->code, 'pages' => $book->pages, 'copies' => $book->copies], $user);

        return $order;
    }

    /**
     * The admin moves a paid book along: pending → printing → shipped → delivered (or cancelled).
     */
    public function transition(PhotoBook $book, string $status, User $admin, ?string $note = null): PhotoBook
    {
        if (! $book->canMoveTo($status)) {
            throw ValidationException::withMessages(['status' => trans('keepsakes.photo_book.errors.transition', ['from' => $book->status, 'to' => $status])]);
        }

        $stamp = ['printing' => 'printing_at', 'shipped' => 'shipped_at', 'delivered' => 'delivered_at'][$status] ?? null;
        $notes = trim((string) $book->tracking_notes);
        if (filled($note)) {
            $notes = trim($notes."\n".now()->format('Y-m-d H:i').' · '.$status.' · '.trim((string) $note));
        }

        $changes = ['status' => $status, 'tracking_notes' => $notes ?: null];
        if ($stamp) {
            $changes[$stamp] = now();
        }
        $book->update($changes);
        AuditLog::record("photo_book.{$status}", $book, array_filter(['note' => $note]), $admin);

        return $book;
    }
}
