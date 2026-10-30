<?php

namespace App\Notifications;

use App\Models\Event;
use App\Services\Digits;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "The photos of … will be deleted on …" (plan section 11), 14 and 3 days
 * before events.storage_until (events:expire-storage), in the host's language,
 * with an "Extend storage" link to the event's upgrade page and its photo cloud add-on.
 */
class StorageExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Event $event, public int $days) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = app()->getLocale();
        $date = CarbonImmutable::parse($this->event->storage_until)->locale($locale)->translatedFormat('j F Y');
        $replace = [
            'event' => (string) $this->event->title,
            'date' => $locale === 'ar' ? Digits::localize($date, 'ar') : $date,
            'days' => $locale === 'ar' ? Digits::localize((string) $this->days, 'ar') : (string) $this->days,
            'name' => (string) ($notifiable->name ?? ''),
        ];

        return (new MailMessage)
            ->subject(trans('app.storage.mail.subject', $replace))
            ->greeting(trans('app.storage.mail.greeting', $replace))
            ->line(trans('app.storage.mail.line', $replace))
            ->line(trans('app.storage.mail.kept'))
            ->action(trans('app.storage.mail.action'), static::extendUrl($this->event))
            ->line(trans('app.storage.mail.ignore'))
            ->salutation(trans('app.storage.mail.salutation'));
    }

    public static function extendUrl(Event $event): string
    {
        return route('dashboard.events.upgrade', ['event' => $event, 'addon' => config('retention.extend_addon')]).'#addons';
    }
}
