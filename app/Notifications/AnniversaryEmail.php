<?php

namespace App\Notifications;

use App\Models\Event;
use App\Services\Album\Album;
use App\Services\Digits;
use App\Services\Journey\Anniversaries;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * "One year since …" (phase 8D, App\Services\Journey\Anniversaries): reopens
 * the invitation and, while it is still kept, the album; suggests the next
 * occasion with the loyalty discount. Marketing email: it carries a signed
 * unsubscribe link and the List-Unsubscribe headers (one click, RFC 8058).
 */
class AnniversaryEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Event $event, public int $year) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) && ! ($notifiable->marketing_opt_out ?? false) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->locale ?? ($notifiable instanceof HasLocalePreference ? $notifiable->preferredLocale() : app()->getLocale());
        $replace = ['event' => (string) $this->event->title, 'name' => $notifiable->name ?? '', 'years' => Digits::localize($this->year, $locale)];
        $unsubscribe = Anniversaries::unsubscribeUrl($notifiable);

        $mail = (new MailMessage)
            ->subject(trans('journey.anniversary.mail.subject', $replace, $locale))
            ->greeting(trans('journey.anniversary.mail.greeting', $replace, $locale))
            ->line(trans('journey.anniversary.mail.line', $replace, $locale))
            ->action(trans('journey.anniversary.mail.invite', [], $locale), route('dashboard.events.show', $this->event));

        if ($this->albumKept()) {
            $mail->line(trans('journey.anniversary.mail.album', ['url' => route('dashboard.events.album', $this->event)], $locale));
        }

        $mail->line(trans('journey.anniversary.mail.suggest', [], $locale));
        if (config('journey.loyalty.enabled') && (int) config('journey.loyalty.percent') > 0) {
            $mail->line(trans('journey.anniversary.mail.loyalty', ['percent' => Digits::localize((int) config('journey.loyalty.percent'), $locale)], $locale));
        }

        return $mail
            ->line(trans('journey.anniversary.mail.action', ['url' => route('create')], $locale))
            ->line(trans('journey.anniversary.mail.unsubscribe', ['url' => $unsubscribe], $locale))
            ->withSymfonyMessage(function (Email $message) use ($unsubscribe) {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$unsubscribe.'>');
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }

    /** The album is still there: media not purged, inside the storage period, album unlocked. */
    private function albumKept(): bool
    {
        $event = $this->event;

        return $event->media_purged_at === null
            && ($event->storage_until === null || $event->storage_until->endOfDay()->isFuture())
            && app(Album::class)->enabled($event);
    }
}
