<?php

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Services\Digits;
use App\Services\Org\OrgPlans;
use App\Services\Payments\CheckoutResult;
use App\Services\Payments\Currencies;
use App\Services\Payments\PaymentGateway;

/**
 * Bank transfer, receipt over WhatsApp, approved by an admin (plan section 12.2):
 * the main method in Syria and Saudi Arabia. A currency is offered when its
 * bank account is configured (config/payments.php "transfer.accounts").
 * Approval happens in Filament through OrderFulfillment::approveTransfer.
 */
class TransferGateway implements PaymentGateway
{
    public const ID = 'transfer';

    /** Account fields shown to the customer, in this order. */
    public const FIELDS = ['account_name', 'bank', 'iban', 'account_number', 'swift'];

    public function id(): string
    {
        return self::ID;
    }

    public function method(): string
    {
        return 'transfer';
    }

    public function enabled(): bool
    {
        return (bool) config('payments.transfer.enabled') && $this->currencies() !== [];
    }

    public function currencies(): array
    {
        return array_values(array_filter(
            Currencies::all(),
            fn (string $currency) => $this->account($currency) !== null,
        ));
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array($currency, $this->currencies(), true);
    }

    public function start(Order $order, string $returnTo = 'dashboard'): CheckoutResult
    {
        return CheckoutResult::instructions($this->instructions($order));
    }

    /**
     * The bank account for a currency, or null when none is configured.
     *
     * @return array<string, string>|null
     */
    public function account(string $currency): ?array
    {
        $account = config("payments.transfer.accounts.{$currency}");

        if (! is_array($account) || (blank($account['iban'] ?? null) && blank($account['account_number'] ?? null))) {
            return null;
        }

        return array_filter(
            array_intersect_key($account, array_flip([...self::FIELDS, 'note'])),
            fn ($value) => filled($value),
        );
    }

    /**
     * What the order page shows: the account, the exact amount, the code to
     * write in the transfer note, and the WhatsApp link for the receipt.
     *
     * @return array<string, mixed>
     */
    public function instructions(Order $order, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $account = $this->account($order->currency) ?? [];

        return [
            'account' => collect(self::FIELDS)
                ->filter(fn (string $field) => isset($account[$field]))
                ->map(fn (string $field) => ['field' => $field, 'label' => trans("app.payments.transfer.fields.{$field}", [], $locale), 'value' => $account[$field]])
                ->values()
                ->all(),
            'note' => $account['note'] ?? null,
            // in Syria most transfers go through local companies rather than banks
            'local_note' => $order->currency === 'SYP' ? trans('app.payments.transfer.local_note_syp', [], $locale) : null,
            'amount' => Currencies::format($order->total_cents, $order->currency, $locale),
            'code' => $order->code,
            'whatsapp_url' => $this->whatsappUrl($order, $locale),
            'expires_at' => $order->expires_at?->toIso8601String(),
        ];
    }

    /**
     * wa.me link to the payments number with the order code, amount and plan.
     */
    public function whatsappUrl(Order $order, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $number = preg_replace('/\D+/', '', (string) config('payments.whatsapp_number'));

        $text = trans('app.payments.transfer.whatsapp_message', [
            'code' => $order->code,
            'amount' => Currencies::format($order->total_cents, $order->currency, $locale),
            'items' => static::itemsLabel($order, $locale),
        ], $locale);

        return 'https://wa.me/'.$number.'?text='.rawurlencode($text);
    }

    /**
     * "Premium + Photo cloud · 90 days": the order's lines in the customer's language.
     */
    public static function itemsLabel(Order $order, string $locale): string
    {
        return collect($order->items ?? [])
            ->map(function (array $item) use ($locale) {
                if ($item['type'] === 'subscription') {
                    return OrgPlans::lineName((string) $item['id'], $locale);
                }
                $key = $item['type'] === 'plan' ? "app.wizard.tiers.{$item['id']}" : "app.checkout.addons.{$item['id']}.name";
                $name = trans($key, [], $locale);
                $name = $name === $key ? ($item['name'] ?? $item['id']) : $name;

                return ($item['quantity'] ?? 1) > 1 ? $name.' × '.Digits::localize($item['quantity'], $locale) : $name;
            })
            ->implode(' + ');
    }
}
