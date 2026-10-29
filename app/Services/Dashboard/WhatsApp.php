<?php

namespace App\Services\Dashboard;

use App\Models\Event;
use App\Models\Household;

/**
 * Personal wa.me links (plan section 19: "wa.me share links per guest").
 * Sending stays manual until the WhatsApp Cloud API arrives: the host taps
 * each link and WhatsApp opens with the message ready.
 *
 * The wording comes from events.messages (edited on the dashboard) or the
 * defaults in lang/{ar,en}/app.php under dashboard.messages, written in the
 * invitation's own language. Placeholders: :name, :title, :link.
 */
class WhatsApp
{
    public const KINDS = ['invite', 'reminder'];

    public const MAX_TEMPLATE = 1000;

    public const PLACEHOLDERS = [':name', ':title', ':link'];

    /**
     * The language guests read: the invitation's, else the dashboard's.
     */
    public static function locale(Event $event): string
    {
        $locale = $event->invitation?->config['locale'] ?? null;

        return in_array($locale, ['ar', 'en'], true) ? $locale : app()->getLocale();
    }

    /**
     * @return array{invite: string, reminder: string}
     */
    public static function templates(Event $event): array
    {
        $locale = static::locale($event);
        $custom = is_array($event->messages) ? $event->messages : [];

        $templates = [];
        foreach (self::KINDS as $kind) {
            $text = $custom[$kind] ?? null;
            $templates[$kind] = is_string($text) && trim($text) !== '' ? $text : static::defaultTemplate($kind, $locale);
        }

        return $templates;
    }

    public static function defaultTemplate(string $kind, string $locale): string
    {
        return (string) trans('app.dashboard.messages.'.$kind.'_default', [], $locale);
    }

    /**
     * The guest's personal link, on the short guest domain when configured.
     */
    public static function link(Household $household): string
    {
        return rtrim((string) config('app.invite_url'), '/').route('invite.show', $household->token, false);
    }

    /**
     * @param  array{invite: string, reminder: string}|null  $templates
     */
    public static function message(Event $event, Household $household, string $kind = 'invite', ?array $templates = null): string
    {
        $template = ($templates ?? static::templates($event))[$kind];

        return strtr($template, [
            ':name' => $household->display_name,
            ':title' => (string) $event->title,
            ':link' => static::link($household),
        ]);
    }

    /**
     * wa.me/<number>?text=… when the guest has a phone, else wa.me/?text=…
     * (WhatsApp then asks the host whom to send it to).
     */
    public static function url(?string $phone, string $text): string
    {
        return 'https://wa.me/'.(PhoneNumber::forWhatsApp($phone) ?? '').'?text='.rawurlencode($text);
    }
}
