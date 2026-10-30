<?php

namespace App\Notifications;

use App\Models\OrgInvitation;
use App\Services\Digits;
use App\Services\Org\WhiteLabel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "قاعة النور invited you…": a team invitation, or (role client) the couple
 * invited to edit their invitation. Sent to an email address, in the
 * inviter's language. Client emails go out under the organization's sender
 * name when it has white label (App\Services\Org\WhiteLabel).
 */
class OrgInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public OrgInvitation $invitation, public string $url, string $locale)
    {
        $this->locale = $locale;
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = $this->invitation->loadMissing(['organization', 'event', 'inviter']);
        $org = $invitation->organization;
        $locale = $this->locale ?? app()->getLocale();
        $replace = [
            'org' => $org->name,
            'inviter' => (string) $invitation->inviter?->name,
            'role' => trans('org.roles.'.$invitation->role, [], $locale),
            'event' => (string) $invitation->event?->title,
            'days' => Digits::localize((string) config('org.invitation_days', 7), $locale),
        ];
        $key = $invitation->isClient() ? 'org.mail.client' : 'org.mail.team';

        $mail = (new MailMessage)
            ->subject(trans("{$key}.subject", $replace, $locale))
            ->greeting(trans('org.mail.greeting', [], $locale))
            ->line(trans("{$key}.line", $replace, $locale))
            ->action(trans("{$key}.action", [], $locale), $this->url)
            ->line(trans('org.mail.expires', $replace, $locale));

        if ($invitation->isClient() && ($sender = app(WhiteLabel::class)->senderName($org))) {
            $mail->from((string) config('mail.from.address'), $sender);
        }

        return $mail;
    }
}
