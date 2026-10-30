<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrgSubscription;
use App\Services\Digits;
use App\Services\Org\OrgPlans;
use App\Services\Payments\Currencies;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Subscription emails to the organization's owner (App\Services\Org\OrgSubscriptions):
 *   renewal_order   the transfer renewal order is ready (code, amount, 48 h)
 *   renewal_7 / renewal_1   reminders before a transfer period ends
 *   past_due        a payment failed or the period ended unpaid: grace period
 *   expired         the grace period is over: the org is back to no plan
 */
class OrgSubscriptionNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public OrgSubscription $subscription, public string $kind, public ?Order $order = null)
    {
        $this->afterCommit();
    }

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
        $s = $this->subscription->loadMissing('organization');
        $date = $s->current_period_end?->locale($locale)->translatedFormat('j F Y') ?? '';
        $graceEnd = ($s->past_due_since ?? now())->addDays((int) config('org.grace_days', 7))->locale($locale)->translatedFormat('j F Y');
        $kind = str_starts_with($this->kind, 'renewal_') && $this->kind !== 'renewal_order' ? 'renewal' : $this->kind;
        $replace = [
            'org' => (string) $s->organization?->name,
            'plan' => trans('org.plans.'.OrgPlans::tierOf($s->plan_id).'.name', [], $locale),
            'date' => Digits::localize($date, $locale),
            'grace_end' => Digits::localize($graceEnd, $locale),
            'days' => Digits::localize(substr($this->kind, 8) ?: '0', $locale),
            'code' => (string) $this->order?->code,
            'amount' => $this->order ? Currencies::format($this->order->total_cents, $this->order->currency, $locale) : '',
        ];

        $mail = (new MailMessage)
            ->subject(trans("org.mail.subscription.{$kind}.subject", $replace, $locale))
            ->greeting(trans('org.mail.greeting', [], $locale))
            ->line(trans("org.mail.subscription.{$kind}.line", $replace, $locale));

        if ($this->order) {
            $mail->line(trans('org.mail.subscription.order_line', $replace, $locale));
        }

        return $mail->action(
            trans('org.mail.subscription.action', [], $locale),
            $this->order ? route('dashboard.orders.show', $this->order) : route('org.billing', $s->org_id),
        );
    }
}
