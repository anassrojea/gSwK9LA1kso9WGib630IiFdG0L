<?php

namespace App\Services\InvitationConfig;

/**
 * Turns the theme block of a config into CSS custom properties and a Google
 * Fonts URL (only the fonts the theme uses, display=swap; plan section 21).
 */
class ThemeStyles
{
    /** Derived tokens used by the shared invitation CSS when a theme does not set them. */
    private const DERIVED = [
        'goldLight' => 'color-mix(in srgb, var(--c-gold) 55%, white)',
        'surface' => 'color-mix(in srgb, var(--c-accent) 8%, var(--c-bg))',
        'backdrop' => 'color-mix(in srgb, var(--c-accent) 55%, black)',
        'label' => 'var(--c-gold)',
        'onAccent' => '#ffffff',
    ];

    public static function cssVariables(array $theme): string
    {
        $vars = [];

        foreach ($theme['colors'] ?? [] as $name => $value) {
            if (preg_match('/^#[0-9a-fA-F]{3,6}$/', $value)) {
                $vars['--c-'.self::kebab($name)] = $value;
            }
        }

        foreach (self::DERIVED as $name => $fallback) {
            $vars['--c-'.self::kebab($name)] ??= $fallback;
        }

        foreach (['display' => 'serif', 'body' => 'serif', 'label' => 'sans-serif'] as $role => $generic) {
            $font = self::fontName($theme['fonts'][$role] ?? null);
            $vars['--f-'.$role] = $font ? "\"{$font}\", {$generic}" : $generic;
        }

        $vars['--radius'] = ((int) ($theme['radius'] ?? 8)).'px';

        return collect($vars)->map(fn ($v, $k) => "{$k}:{$v}")->implode(';');
    }

    public static function fontsUrl(array $theme): ?string
    {
        $families = collect($theme['fonts'] ?? [])
            ->map(fn ($font) => self::fontName($font))
            ->filter()
            ->unique()
            ->map(fn ($font) => 'family='.str_replace(' ', '+', $font).':wght@400;700')
            ->implode('&');

        return $families ? "https://fonts.googleapis.com/css2?{$families}&display=swap" : null;
    }

    private static function fontName(?string $font): ?string
    {
        return $font && preg_match('/^[A-Za-z0-9 ]{2,40}$/', $font) ? $font : null;
    }

    private static function kebab(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }
}
