<?php

namespace App\Services\Catalog;

use App\Services\InvitationConfig\InvitationConfigValidator;

/**
 * Checks what the admin types into the catalog against the invitation config
 * schema (v1, frozen: app/Services/InvitationConfig/schemas), with errors an
 * admin can read: keyed by path ("sections.2 (date).start") instead of JSON pointers.
 */
class CatalogValidator
{
    /** Theme colour keys the schema requires, then the optional ones the shared CSS reads (ThemeStyles). */
    public const REQUIRED_COLORS = ['bg', 'ink', 'accent', 'soft', 'gold'];

    public const OPTIONAL_COLORS = ['goldLight', 'surface', 'backdrop', 'label', 'onAccent'];

    public const FONT_ROLES = ['display', 'body', 'label'];

    public function __construct(private InvitationConfigValidator $validator) {}

    /**
     * A theme row (tokens + fonts) checked as the theme block of a config.
     *
     * @return array<string, string> form field (tokens.colors.bg, fonts.display, tokens.radius, id) => message
     */
    public function themeErrors(string $id, array $tokens, array $fonts): array
    {
        $theme = [
            'id' => $id,
            'colors' => static::filled($tokens['colors'] ?? []),
            'fonts' => static::filled($fonts),
        ];
        if (isset($tokens['radius']) && $tokens['radius'] !== '') {
            $theme['radius'] = is_numeric($tokens['radius']) ? (int) $tokens['radius'] : $tokens['radius'];
        }

        $errors = $this->validator->validate($this->sampleConfig($theme));

        $fields = [];
        foreach ($errors as $pointer => $messages) {
            if (! str_starts_with($pointer, '/theme')) {
                continue;
            }
            $path = array_values(array_filter(explode('/', substr($pointer, strlen('/theme')))));
            $field = match ($path[0] ?? null) {
                'colors' => isset($path[1]) ? 'tokens.colors.'.$path[1] : 'tokens.colors.bg',
                'fonts' => isset($path[1]) ? 'fonts.'.$path[1] : 'fonts.display',
                'radius' => 'tokens.radius',
                'id' => 'id',
                default => 'tokens.colors.bg',
            };
            $fields[$field] ??= $this->friendly($messages[0] ?? '', $field);
        }

        return $fields;
    }

    /**
     * A template's default_config, plus the columns it must agree with.
     *
     * @return list<string> readable problems, empty when valid
     */
    public function templateErrors(mixed $config, ?string $occasion = null, ?string $openingId = null, ?string $themeId = null): array
    {
        if (! is_array($config) || array_is_list($config)) {
            return [trans('admin.templates.errors.not_object')];
        }

        $problems = [];
        foreach ($this->validator->validate($config) as $pointer => $messages) {
            foreach ($messages as $message) {
                $problems[] = $this->path($config, $pointer).': '.$message;
            }
        }

        if ($problems === []) {
            foreach (['occasion' => [$occasion, $config['occasion'] ?? null], 'opening.id' => [$openingId, $config['opening']['id'] ?? null], 'theme.id' => [$themeId, $config['theme']['id'] ?? null]] as $path => [$column, $value]) {
                if ($column !== null && $column !== $value) {
                    $problems[] = trans('admin.templates.errors.mismatch', ['path' => $path, 'value' => (string) $value, 'expected' => $column]);
                }
            }
        }

        return $problems;
    }

    /**
     * "/sections/2/start" → "sections.2 (date).start", "/" → "(root)".
     */
    public function path(array $config, string $pointer): string
    {
        $segments = array_values(array_filter(explode('/', $pointer), fn ($s) => $s !== ''));
        if ($segments === []) {
            return '(root)';
        }

        $parts = [];
        $node = $config;
        foreach ($segments as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            $node = is_array($node) ? ($node[$segment] ?? null) : null;
            $parts[] = ctype_digit($segment) && is_array($node) && isset($node['type']) ? "{$segment} ({$node['type']})" : $segment;
        }

        return implode('.', $parts);
    }

    /**
     * @return array<string, mixed> the entries that hold a value (empty optional colours are dropped)
     */
    public static function filled(array $values): array
    {
        return array_filter($values, fn ($v) => $v !== null && $v !== '');
    }

    private function friendly(string $message, string $field): string
    {
        return match (true) {
            str_starts_with($field, 'tokens.colors.') => trans('admin.themes.errors.color'),
            str_starts_with($field, 'fonts.') => trans('admin.themes.errors.font'),
            $field === 'tokens.radius' => trans('admin.themes.errors.radius'),
            $field === 'id' => trans('admin.catalog.errors.slug'),
            default => $message,
        };
    }

    private function sampleConfig(array $theme): array
    {
        return [
            'version' => InvitationConfigValidator::CURRENT_VERSION,
            'occasion' => 'other',
            'locale' => 'ar',
            'opening' => ['id' => 'envelope'],
            'theme' => $theme,
            'sections' => [['type' => 'names', 'first' => 'Daawa']],
        ];
    }
}
