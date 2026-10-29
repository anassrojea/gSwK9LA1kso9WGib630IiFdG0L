<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Tables\EventsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The customer's events (read-only); each opens as the customer sees it.
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans('admin.events.plural');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return EventsTable::configure($table, withOwner: false)
            ->recordUrl(fn (Model $record) => EventResource::getUrl('view', ['record' => $record]));
    }
}
