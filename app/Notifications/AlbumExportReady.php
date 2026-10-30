<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The album ZIP is ready (App\Jobs\ExportAlbumZip): a signed link that expires.
 */
class AlbumExportReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Event $event, public string $url, public int $count) {}

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
        $replace = ['event' => (string) $this->event->title, 'count' => $this->count, 'days' => (int) config('album.export_days')];

        return (new MailMessage)
            ->subject(trans('album.mail.subject', $replace, $locale))
            ->greeting(trans('album.mail.greeting', ['name' => $notifiable->name ?? ''], $locale))
            ->line(trans_choice('album.mail.line', $this->count, $replace, $locale))
            ->action(trans('album.mail.action', [], $locale), $this->url)
            ->line(trans('album.mail.expires', $replace, $locale));
    }
}
