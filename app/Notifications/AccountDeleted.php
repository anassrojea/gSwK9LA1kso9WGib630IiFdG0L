<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The last email, sent to the address of an account that has just been purged.
 */
class AccountDeleted extends Notification
{
    public function __construct(public string $name) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->locale ?? app()->getLocale();

        return (new MailMessage)
            ->subject(trans('app.account.privacy.mail.deleted.subject', [], $locale))
            ->greeting(trans('app.payments.mail.greeting', ['name' => $this->name], $locale))
            ->line(trans('app.account.privacy.mail.deleted.line', [], $locale))
            ->line(trans('app.account.privacy.mail.deleted.kept', [], $locale));
    }
}
