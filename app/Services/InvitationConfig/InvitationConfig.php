<?php

namespace App\Services\InvitationConfig;

/**
 * Helpers around the invitation config document: upgrading old versions so
 * they keep rendering, reading sections, and the default config used when a
 * template has none.
 */
class InvitationConfig
{
    /** v2 "kind" values; absent means invitation. */
    public const KINDS = ['invitation', 'save_the_date', 'thank_you'];

    /**
     * Upgrade a config to the current schema version. Add a step here each
     * time the schema version is bumped (v1 → v2 …), never edit old schemas.
     */
    public static function upgrade(array $config): array
    {
        $version = (int) ($config['version'] ?? 1);

        while ($version < InvitationConfigValidator::CURRENT_VERSION) {
            $config = match ($version) {
                1 => self::v1ToV2($config),
                default => $config,
            };
            $config['version'] = ++$version;
        }

        return $config;
    }

    /**
     * v1 → v2 (phase 8D): v2 only adds things (occasions, the optional kind,
     * the thank_you_gallery section), so a v1 document is already a valid v2
     * document once its version says so. Nothing else changes; an absent kind
     * means "invitation".
     */
    private static function v1ToV2(array $config): array
    {
        return $config;
    }

    /**
     * What the document is (v2 "kind"): invitation, save_the_date or thank_you.
     */
    public static function kind(array $config): string
    {
        return in_array($config['kind'] ?? null, self::KINDS, true) ? $config['kind'] : 'invitation';
    }

    /**
     * Visible sections in order, each with its index for stable DOM ids.
     *
     * @return list<array>
     */
    public static function visibleSections(array $config): array
    {
        return array_values(array_filter(
            $config['sections'] ?? [],
            fn (array $section) => ! ($section['hidden'] ?? false),
        ));
    }

    public static function section(array $config, string $type): ?array
    {
        foreach ($config['sections'] ?? [] as $section) {
            if (($section['type'] ?? null) === $type) {
                return $section;
            }
        }

        return null;
    }
}
