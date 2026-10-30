<?php

namespace App\Filament\Resources\Marketplace;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Shared bits of the marketplace's admin resources: every decision goes through a
 * service (audit-logged there); a refused one becomes a red notification.
 */
class MarketplaceActions
{
    public static function run(Action $action, callable $callback, ?string $success = null): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
            $action->halt();
        } catch (AuthorizationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();
        }

        if ($success) {
            Notification::make()->title($success)->success()->send();
        }
    }

    /**
     * @return array<string, string>
     */
    public static function options(string $prefix, array $values): array
    {
        return collect($values)->mapWithKeys(fn ($v) => [$v => trans("{$prefix}.{$v}")])->all();
    }
}
