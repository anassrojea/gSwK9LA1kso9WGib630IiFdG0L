<?php

namespace App\Notifications;

use App\Models\HighlightVideo;
use App\Services\Keepsakes\HighlightVideos;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The highlight video is ready (App\Jobs\BuildHighlightVideo): signed download
 * links, one per format, that expire after keepsakes.video.link_days.
 */
class HighlightVideoReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public HighlightVideo $video) {}

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
        $videos = app(HighlightVideos::class);
        $days = (int) config('keepsakes.video.link_days', 7);
        $replace = ['event' => (string) $this->video->event?->title, 'days' => $days];

        $mail = (new MailMessage)
            ->subject(trans('keepsakes.video.mail.subject', $replace, $locale))
            ->greeting(trans('keepsakes.video.mail.greeting', ['name' => $notifiable->name ?? ''], $locale))
            ->line(trans('keepsakes.video.mail.line', $replace, $locale));

        $first = true;
        foreach (array_keys($this->video->outputs ?? []) as $format) {
            $url = $videos->url($this->video, $format, $days * 24 * 60, download: true);
            $label = trans("keepsakes.video.mail.download.{$format}", [], $locale);
            $mail = $first ? $mail->action($label, $url) : $mail->line($label.': '.$url);
            $first = false;
        }

        return $mail->line(trans('keepsakes.video.mail.expires', $replace, $locale));
    }
}
