<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An invoice, receipt or credit note (phase 6B, docs/INVOICES.md). Issued by
 * App\Services\Invoicing\Invoicer when an order is paid or approved (kind
 * "invoice") or refunded (kind "credit_note", negative amounts). Immutable
 * once issued: only pdf_path is filled in later, on first download.
 *
 * type: simplified_tax_invoice (VAT-registered seller and the order is in VAT
 * scope, with the ZATCA phase-1 QR) or receipt (no VAT).
 */
class Invoice extends Model
{
    use HasUuids;

    public const KIND_INVOICE = 'invoice';

    public const KIND_CREDIT_NOTE = 'credit_note';

    public const TYPE_TAX = 'simplified_tax_invoice';

    public const TYPE_RECEIPT = 'receipt';

    public const TYPES = [self::TYPE_TAX, self::TYPE_RECEIPT];

    protected $fillable = [
        'number', 'series', 'sequence', 'kind', 'type', 'credit_for_id', 'order_id', 'user_id', 'customer', 'seller',
        'lines', 'currency', 'gross_cents', 'discount_cents', 'subtotal_cents', 'vat_cents', 'total_cents',
        'vat_rate_bp', 'prices_include_vat', 'locale', 'issued_at', 'qr', 'pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'customer' => 'array',
            'seller' => 'array',
            'lines' => 'array',
            'sequence' => 'integer',
            'gross_cents' => 'integer',
            'discount_cents' => 'integer',
            'subtotal_cents' => 'integer',
            'vat_cents' => 'integer',
            'total_cents' => 'integer',
            'vat_rate_bp' => 'integer',
            'prices_include_vat' => 'boolean',
            'issued_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The invoice a credit note reverses. */
    public function original(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'credit_for_id');
    }

    public function creditNote(): HasOne
    {
        return $this->hasOne(Invoice::class, 'credit_for_id');
    }

    public function isCreditNote(): bool
    {
        return $this->kind === self::KIND_CREDIT_NOTE;
    }

    public function isTaxInvoice(): bool
    {
        return $this->type === self::TYPE_TAX;
    }

    /**
     * "simplified_tax_invoice", "receipt", "simplified_tax_credit_note" or "credit_note" (lang invoices.titles.*).
     */
    public function titleKey(): string
    {
        return match (true) {
            $this->isCreditNote() && $this->isTaxInvoice() => 'simplified_tax_credit_note',
            $this->isCreditNote() => 'credit_note',
            default => $this->type,
        };
    }

    /**
     * @param  Builder<Invoice>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey());
    }
}
