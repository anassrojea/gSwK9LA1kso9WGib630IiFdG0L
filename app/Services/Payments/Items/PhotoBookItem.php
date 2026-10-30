<?php

namespace App\Services\Payments\Items;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\PhotoBook;
use Carbon\CarbonImmutable;

/**
 * Order line "photo_book" (plan section 14.5, photo_book_20 / photo_book_40,
 * quantity = copies): the book the order was placed for (photo_books.order_id)
 * goes from draft to pending, for the admin to print and ship (Filament → Photo books).
 * A refund cancels a book that has not shipped yet.
 */
class PhotoBookItem extends FeatureItem
{
    public function fulfill(Order $order, array $line, ?Event $event, CarbonImmutable $paidAt): void
    {
        parent::fulfill($order, $line, $event, $paidAt);

        PhotoBook::query()->where('order_id', $order->getKey())->where('status', 'draft')->get()
            ->each(function (PhotoBook $book) use ($line, $paidAt, $order) {
                $book->update(['status' => 'pending', 'paid_at' => $paidAt, 'copies' => max(1, (int) ($line['quantity'] ?? 1))]);
                AuditLog::record('photo_book.paid', $book, ['order' => $order->code, 'copies' => $book->copies]);
            });
    }

    public function refund(Order $order, array $line, ?Event $event): void
    {
        PhotoBook::query()->where('order_id', $order->getKey())->whereIn('status', ['draft', 'pending', 'printing'])->get()
            ->each(function (PhotoBook $book) use ($order) {
                $book->update(['status' => 'cancelled']);
                AuditLog::record('photo_book.cancelled', $book, ['order' => $order->code, 'reason' => 'refund']);
            });
    }
}
