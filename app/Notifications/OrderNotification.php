<?php

namespace App\Notifications;

use App\Models\Order;
use App\Services\Payments\Currencies;
use App\Services\Payments\Gateways\TransferGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Order emails (mail channel; a WhatsApp channel comes with the Cloud API).
 * Sent in the customer's language (User::preferredLocale), after the
 * database transaction that changed the order commits.
 */
abstract class OrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order)
    {
        $this->afterCommit();
    }

    /** lang key under app.payments.mail */
    abstract protected function kind(): string;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->locale ?? ($notifiable instanceof HasLocalePreference ? $notifiable->preferredLocale() : app()->getLocale());
        $replace = [
            'code' => $this->order->code,
            'amount' => Currencies::format($this->order->total_cents, $this->order->currency, $locale),
            'items' => TransferGateway::itemsLabel($this->order, $locale),
            'event' => (string) $this->order->event?->title,
            'reason' => (string) $this->order->rejection_reason,
        ];
        $key = 'app.payments.mail.'.$this->kind();

        $mail = (new MailMessage)
            ->subject(trans("{$key}.subject", $replace, $locale))
            ->greeting(trans('app.payments.mail.greeting', ['name' => $notifiable->name ?? ''], $locale))
            ->line(trans("{$key}.line", $replace, $locale));

        if ($this->kind() === 'rejected' && filled($this->order->rejection_reason)) {
            $mail->line(trans('app.payments.mail.rejected.reason', $replace, $locale));
        }

        return $mail->action(trans("{$key}.action", [], $locale), route('dashboard.orders.show', $this->order));
    }
}
