<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\Account\AccountDeletion;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your account will be deleted on …", with a way back (plan section 20).
 */
class AccountDeletionRequested extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $locale = $notifiable->preferredLocale();
        $date = AccountDeletion::formatDate(AccountDeletion::scheduledFor($notifiable), $locale);

        return (new MailMessage)
            ->subject(trans('app.account.privacy.mail.requested.subject', [], $locale))
            ->greeting(trans('app.payments.mail.greeting', ['name' => $notifiable->name], $locale))
            ->line(trans('app.account.privacy.mail.requested.line', ['date' => $date], $locale))
            ->line(trans('app.account.privacy.mail.requested.kept', [], $locale))
            ->action(trans('app.account.privacy.mail.requested.action', [], $locale), route('account.privacy'))
            ->line(trans('app.account.privacy.mail.requested.not_you', [], $locale));
    }
}
