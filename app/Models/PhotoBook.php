<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A printed photo book (phase 8D, plan section 14.5). The host picks photos
 * (or auto-pick), a title and a layout; the print-ready PDF is made by
 * App\Services\Keepsakes\PhotoBookPdf. Ordered as the photo_book order item;
 * the admin moves it along in Filament:
 *
 *   draft → pending (paid) → printing → shipped → delivered; cancelled at any point
 */
class PhotoBook extends Model
{
    use HasUuids;

    public const STATUSES = ['draft', 'pending', 'printing', 'shipped', 'delivered', 'cancelled'];

    /** What the admin may move a book to from each status. */
    public const NEXT = [
        'draft' => ['cancelled'],
        'pending' => ['printing', 'cancelled'],
        'printing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public const SIZES = ['a4_landscape', 'square_21'];

    public const PAGES = [20, 40];

    protected $fillable = [
        'event_id', 'user_id', 'order_id', 'title', 'subtitle', 'size', 'pages', 'auto_pick', 'photo_ids', 'copies',
        'ship_name', 'ship_country', 'ship_city', 'ship_address', 'ship_phone', 'status', 'tracking_notes',
        'pdf_key', 'pdf_pages', 'pdf_at', 'paid_at', 'printing_at', 'shipped_at', 'delivered_at',
    ];

    protected $attributes = ['status' => 'draft', 'size' => 'a4_landscape', 'pages' => 20, 'auto_pick' => true, 'copies' => 1];

    protected function casts(): array
    {
        return [
            'photo_ids' => 'array',
            'pages' => 'integer',
            'copies' => 'integer',
            'pdf_pages' => 'integer',
            'auto_pick' => 'boolean',
            'pdf_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'printing_at' => 'immutable_datetime',
            'shipped_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** The add-on (and order line) for this layout: photo_book_20 or photo_book_40. */
    public function addonId(): string
    {
        return 'photo_book_'.$this->pages;
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::NEXT[$this->status] ?? [], true);
    }
}
