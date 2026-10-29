<?php

namespace App\Notifications;

use App\Services\Digits;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A six-digit code (App\Services\Account\LoginCodes). Sent right away, not
 * queued: the person is waiting on the page for it.
 */
class LoginCodeNotification extends Notification
{
    public function __construct(public string $code, public string $purpose) {}

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
        $key = "app.auth.code.mail.{$this->purpose}";
        $minutes = Digits::localize((int) config('account.login_codes.ttl_minutes', 10), $locale);

        return (new MailMessage)
            ->subject(trans("{$key}.subject", ['code' => $this->code], $locale))
            ->greeting(trans('app.auth.code.mail.greeting', [], $locale))
            ->line(trans("{$key}.line", [], $locale))
            // Western digits in the code itself: it is typed back, often on a Latin keyboard
            ->line('**'.$this->code.'**')
            ->line(trans('app.auth.code.mail.expires', ['minutes' => $minutes], $locale))
            ->line(trans('app.auth.code.mail.ignore', [], $locale));
    }
}
