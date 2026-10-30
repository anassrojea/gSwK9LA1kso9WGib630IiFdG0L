<?php

namespace App\Services\OgImage;

use App\Models\Household;
use App\Models\Invitation;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\InvitationConfig\ThemeStyles;
use App\Services\PublishGate;
use App\View\InviteDate;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The link preview image WhatsApp shows when a guest link is shared (plan
 * sections 14.1 and 15.1): 1200 × 630, "دعوة خاصة لعائلة خالد العلي" with the
 * names, the date and the theme's colors and fonts. Personal per household on
 * events with personal links (Premium); one generic image for the open link and
 * for every guest of a Free event (subject()).
 *
 * Images are cached on the media disk at og/{event_id}/{household_id|open}-{hash}.png.
 * The hash covers everything drawn (the household's name, the invitation's
 * updated_at, the theme and locale), so an edit gives a new file and the old
 * one is deleted when the new one is written.
 */
class OgImage
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /** Bump when the card's design changes, to re-render every image. */
    public const VERSION = 1;

    /** @var array<string, bool> event id => personal cards allowed (one check per event per instance) */
    private array $personal = [];

    public function __construct(
        private Chromium $chromium,
        private FontEmbedder $fonts,
        private PublishGate $gate,
    ) {}

    /**
     * The household the card is drawn for: null (the generic card, as on the open link)
     * unless the event has personal previews (Premium, PublishGate::canShowPersonalPreview).
     * Every method below goes through this, so a Free event's personal link gets the
     * generic image under the same og.png URL.
     */
    public function subject(Invitation $invitation, ?Household $household): ?Household
    {
        if ($household === null) {
            return null;
        }

        $event = $invitation->event ?? $household->event;
        if (! $event) {
            return null;
        }

        return ($this->personal[$event->getKey()] ??= $this->gate->canShowPersonalPreview($event)) ? $household : null;
    }

    public function available(): bool
    {
        return $this->chromium->available();
    }

    public function hash(Invitation $invitation, array $config, ?Household $household = null): string
    {
        $household = $this->subject($invitation, $household);

        return substr(sha1(json_encode([
            self::VERSION,
            $household?->display_name,
            $invitation->updated_at?->toIso8601String(),
            $config['theme'] ?? null,
            $config['locale'] ?? null,
        ], JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    public function path(Invitation $invitation, ?Household $household, string $hash): string
    {
        $household = $this->subject($invitation, $household);

        return 'og/'.$invitation->event_id.'/'.($household?->getKey() ?? 'open').'-'.$hash.'.png';
    }

    /**
     * Absolute URL for the og:image tag, on the guest domain. ?v= changes with
     * every edit, so WhatsApp and the edge cache never keep an outdated card.
     */
    public function publicUrl(string $token, string $hash): string
    {
        return rtrim((string) config('app.invite_url'), '/').route('invite.og', $token, false).'?v='.$hash;
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.media_disk', 'public'));
    }

    /**
     * The image's path on the media disk, rendered first if it is missing.
     */
    public function ensure(Invitation $invitation, ?Household $household = null, ?int $timeout = null): string
    {
        $household = $this->subject($invitation, $household);
        $config = $invitation->renderConfig();
        $path = $this->path($invitation, $household, $this->hash($invitation, $config, $household));
        $disk = $this->disk();

        if ($disk->exists($path)) {
            return $path;
        }

        $png = $this->chromium->screenshot($this->html($invitation, $config, $household), self::WIDTH, self::HEIGHT, $timeout ?? config('og.timeout'));
        $disk->put($path, $png);

        // older versions of the same card
        $prefix = ($household?->getKey() ?? 'open').'-';
        foreach ($disk->files('og/'.$invitation->event_id) as $file) {
            if ($file !== $path && str_starts_with(basename($file), $prefix)) {
                $disk->delete($file);
            }
        }

        return $path;
    }

    /**
     * The card as a self-contained HTML document (resources/views/og/card.blade.php).
     */
    public function html(Invitation $invitation, array $config, ?Household $household = null): string
    {
        $household = $this->subject($invitation, $household);
        $locale = $config['locale'];
        $names = InvitationConfig::section($config, 'names');
        $dateSection = InvitationConfig::section($config, 'date');
        $date = $dateSection ? InviteDate::fromIso($dateSection['start'], $locale) : null;
        $joiner = $locale === 'ar' ? ' و' : ' & ';
        $couple = $names
            ? trim($names['first'].(isset($names['second']) ? $joiner.$names['second'] : ''))
            : ($invitation->event?->title ?? '');

        return view('og.card', [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'themeVars' => ThemeStyles::cssVariables($config['theme']),
            'fontFaces' => $this->fonts->css($config['theme']),
            'occasion' => trans('invite.occasion.'.$config['occasion'], [], $locale),
            'couple' => $couple,
            'date' => $date ? trim($date->weekday().' '.$date->day().' '.$date->monthYear()) : null,
            'special' => $household ? trans('invite.og.special', [], $locale) : null,
            'recipient' => $household ? static::recipient($household->display_name, $locale) : trans('invite.og.open', [], $locale),
        ])->render();
    }

    /**
     * "لعائلة خالد العلي", "للأستاذ محمد", "إلى خالد العلي"; "for the Ali family".
     * Arabic attaches لـ to family words and to names starting with ال.
     */
    public static function recipient(string $name, string $locale): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        if ($locale !== 'ar') {
            return trans('invite.og.for', ['name' => $name], $locale);
        }

        if (preg_match('/^(عائلة|عائله|أسرة|اسرة|آل)\s/u', $name)) {
            return 'ل'.$name;
        }
        if (Str::startsWith($name, 'ال')) {
            return 'ل'.mb_substr($name, 1);
        }

        return trans('invite.og.for', ['name' => $name], $locale);
    }
}
