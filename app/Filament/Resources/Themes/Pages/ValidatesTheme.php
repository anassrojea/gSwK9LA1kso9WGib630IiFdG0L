<?php

namespace App\Filament\Resources\Themes\Pages;

use App\Services\Catalog\CatalogValidator;
use Illuminate\Validation\ValidationException;

/**
 * The theme form is checked against the schema's theme block before saving;
 * empty optional colours are dropped rather than stored as null.
 */
trait ValidatesTheme
{
    /**
     * @param  array<string, mixed>  $existing  the saved tokens: keys the form doesn't edit are kept
     */
    protected function validatedTheme(array $data, string $id, array $existing = []): array
    {
        $tokens = ($data['tokens'] ?? []) + array_diff_key($existing, ['colors' => true, 'radius' => true]);
        $tokens['colors'] = CatalogValidator::filled($tokens['colors'] ?? []);
        if (! isset($tokens['radius']) || $tokens['radius'] === '') {
            unset($tokens['radius']);
        } elseif (is_numeric($tokens['radius'])) {
            $tokens['radius'] = (int) $tokens['radius'];
        }
        $data['tokens'] = $tokens;
        $data['fonts'] = CatalogValidator::filled($data['fonts'] ?? []);

        $errors = app(CatalogValidator::class)->themeErrors($id, $tokens, $data['fonts']);
        if ($errors !== []) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($message, $field) => ["data.{$field}" => $message])->all());
        }

        return $data;
    }
}
