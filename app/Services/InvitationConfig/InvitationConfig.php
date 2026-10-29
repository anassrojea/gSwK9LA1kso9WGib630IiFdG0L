<?php

namespace App\Services\InvitationConfig;

/**
 * Helpers around the invitation config document: upgrading old versions so
 * they keep rendering, reading sections, and the default config used when a
 * template has none.
 */
class InvitationConfig
{
    /**
     * Upgrade a config to the current schema version. Add a step here each
     * time the schema version is bumped (v1 → v2 …), never edit old schemas.
     */
    public static function upgrade(array $config): array
    {
        $version = (int) ($config['version'] ?? 1);

        while ($version < InvitationConfigValidator::CURRENT_VERSION) {
            $config = match ($version) {
                // 1 => self::v1ToV2($config),
                default => $config,
            };
            $config['version'] = ++$version;
        }

        return $config;
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
