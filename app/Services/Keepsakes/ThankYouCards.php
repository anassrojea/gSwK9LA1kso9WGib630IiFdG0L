<?php

namespace App\Services\Keepsakes;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Media;
use App\Models\ThankYouCard;
use App\Services\Entitlements;
use App\Services\InvitationConfig\InvitationConfig;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The thank-you card and its highlight gallery (phase 8D, plan section 13.2, $7).
 *
 *   - Made from the event after it: the invitation's opening, theme and names,
 *     a thank-you message, photos chosen from the album and the hosts' signature,
 *     as a schema v2 config with kind "thank_you" (thank_you_cards.config).
 *   - Gated by the "thank_you" add-on: the host can prepare it any time, it is
 *     published and shown to guests only with the add-on.
 *   - Sent through the same links: /t/{token} for every household link and the
 *     open link, and, when show_on_invite, /i/{token} itself turns into the card
 *     keepsakes.thank_you.show_after_hours after the event starts.
 *   - The pages stay cacheable: the family's name comes from /i/{token}/state.
 */
class ThankYouCards
{
    public const FEATURE = 'thank_you';

    public function __construct(private Entitlements $entitlements) {}

    public function unlocked(Event $event): bool
    {
        return $this->entitlements->hasFeature($event, self::FEATURE);
    }

    /**
     * The card guests may see: published, paid for, on a live event.
     */
    public function visible(Event $event): ?ThankYouCard
    {
        $card = $event->relationLoaded('thankYouCard') ? $event->thankYouCard : $event->thankYouCard()->first();

        return $card?->published_at && ! $event->isArchived() && $this->unlocked($event) ? $card : null;
    }

    /**
     * /i/{token} shows the card instead of the invitation: after the event, when the host asked for it.
     */
    public function replacesInvite(Event $event): ?ThankYouCard
    {
        $card = $this->visible($event);
        if (! $card || ! $card->show_on_invite || ! $event->starts_at) {
            return null;
        }

        return $event->starts_at->addHours((int) config('keepsakes.thank_you.show_after_hours', 12))->isPast() ? $card : null;
    }

    public function forEvent(Event $event): ThankYouCard
    {
        return $event->thankYouCard()->first()
            ?? ThankYouCard::query()->create(['event_id' => $event->getKey(), 'config' => $this->defaultConfig($event), 'show_on_invite' => true]);
    }

    /**
     * A first draft from the invitation: same opening and theme, the names, the date, a message and the best photos.
     */
    public function defaultConfig(Event $event): array
    {
        $invitation = InvitationConfig::upgrade($event->invitation?->config ?? []);
        $locale = $invitation['locale'] ?? 'ar';
        $names = InvitationConfig::section($invitation, 'names');
        $date = InvitationConfig::section($invitation, 'date');
        $signature = $names ? trim($names['first'].(isset($names['second']) ? ($locale === 'ar' ? ' و' : ' & ').$names['second'] : '')) : null;

        $options = array_intersect_key($invitation['opening']['options'] ?? [], array_flip(['color', 'accentColor', 'petalColor', 'sealText']));

        return [
            'version' => 2,
            'occasion' => 'thank_you',
            'kind' => 'thank_you',
            'locale' => $locale,
            'opening' => [
                'id' => $invitation['opening']['id'] ?? 'envelope',
                'options' => $options + [
                    'kicker' => trans('keepsakes.thank_you.kicker', [], $locale),
                    'headline' => trans('keepsakes.thank_you.headline', [], $locale),
                ],
            ],
            'theme' => $invitation['theme'],
            'effects' => array_intersect_key($invitation['effects'] ?? [], ['fallingPetals' => true, 'sparkles' => true]),
            'sections' => array_values(array_filter([
                $names ? array_diff_key($names, ['hidden' => true, 'note' => true]) : null,
                $date ? ['type' => 'date', 'label' => trans('keepsakes.thank_you.date_label', [], $locale), 'start' => $date['start'], 'showHijri' => (bool) ($date['showHijri'] ?? true), 'countdown' => false] : null,
                array_filter([
                    'type' => 'thank_you_gallery',
                    'title' => trans('keepsakes.thank_you.title', [], $locale),
                    'message' => trans('keepsakes.thank_you.default_message', [], $locale),
                    'photos' => $this->suggested($event, (int) config('keepsakes.thank_you.default_photos', 9))->map(fn (Media $m) => ['src' => 'media:'.$m->getKey()])->values()->all(),
                    'signature' => $signature,
                ], fn ($v) => $v !== null),
                ['type' => 'closing', 'text' => trans('keepsakes.thank_you.closing', [], $locale)],
            ])),
        ];
    }

    /**
     * The host's edits: message, title, signature, the chosen album photos, show on the invitation.
     *
     * @param  array{message?: string, title?: ?string, signature?: ?string, photos?: list<string>, show_on_invite?: bool}  $data
     */
    public function update(ThankYouCard $card, array $data): ThankYouCard
    {
        $config = $card->config;
        $index = collect($config['sections'])->search(fn (array $s) => $s['type'] === 'thank_you_gallery');
        $gallery = $index === false ? ['type' => 'thank_you_gallery', 'message' => trans('keepsakes.thank_you.default_message')] : $config['sections'][$index];

        if (array_key_exists('message', $data)) {
            $gallery['message'] = (string) $data['message'];
        }
        foreach (['title', 'signature'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) $data[$field]);
                if ($value === '') {
                    unset($gallery[$field]);
                } else {
                    $gallery[$field] = $value;
                }
            }
        }
        if (array_key_exists('photos', $data)) {
            $allowed = $this->photos($card->event)->keyBy('id');
            $ids = array_values(array_unique(array_filter((array) $data['photos'], fn ($id) => $allowed->has($id))));
            if (count($ids) > (int) config('keepsakes.thank_you.max_photos', 24)) {
                throw ValidationException::withMessages(['photos' => trans('keepsakes.thank_you.errors.too_many', ['max' => (int) config('keepsakes.thank_you.max_photos', 24)])]);
            }
            $gallery['photos'] = array_map(fn (string $id) => ['src' => 'media:'.$id], $ids);
        }

        if ($index === false) {
            $config['sections'][] = $gallery;
        } else {
            $config['sections'][$index] = $gallery;
        }

        $card->config = $config;
        if (array_key_exists('show_on_invite', $data)) {
            $card->show_on_invite = (bool) $data['show_on_invite'];
        }
        $card->save();

        return $card;
    }

    public function publish(ThankYouCard $card, bool $publish = true): ThankYouCard
    {
        if ($publish && ! $this->unlocked($card->event)) {
            throw ValidationException::withMessages(['thank_you' => trans('keepsakes.thank_you.errors.locked')]);
        }

        $card->published_at = $publish ? ($card->published_at ?? now()) : null;
        $card->save();
        AuditLog::record($publish ? 'thank_you.published' : 'thank_you.unpublished', $card->event);

        return $card;
    }

    /**
     * Album photos guests can see (hidden or awaiting approval are left out), oldest first.
     *
     * @return Collection<int, Media>
     */
    public function photos(Event $event): Collection
    {
        return Media::query()->album()->visible()->where('event_id', $event->getKey())->where('kind', 'photo')
            ->orderBy('created_at')->get();
    }

    /**
     * $count photos spread evenly over the event (by upload time), for a first draft.
     *
     * @return Collection<int, Media>
     */
    public function suggested(Event $event, int $count): Collection
    {
        return static::spread($this->photos($event), $count);
    }

    /**
     * Evenly spaced items of an ordered list (the first and the last included).
     *
     * @template T
     *
     * @param  Collection<int, T>  $items
     * @return Collection<int, T>
     */
    public static function spread(Collection $items, int $count): Collection
    {
        $items = $items->values();
        $total = $items->count();
        if ($count <= 0 || $total === 0) {
            return collect();
        }
        if ($total <= $count) {
            return $items;
        }
        if ($count === 1) {
            return collect([$items[0]]);
        }

        return collect(range(0, $count - 1))
            ->map(fn (int $i) => $items[(int) round($i * ($total - 1) / ($count - 1))])
            ->values();
    }
}
