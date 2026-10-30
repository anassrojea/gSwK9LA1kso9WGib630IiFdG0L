<?php

namespace App\Filament\Resources\Organizations\RelationManagers;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The organization's events (the events table, read-only), with whether each used a signature slot.
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans('org.admin.events');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return EventsTable::configure($table)
            ->recordUrl(fn (Event $record) => EventResource::getUrl('view', ['record' => $record]))
            ->pushColumns([
                IconColumn::make('org_quota_at')->label(trans('org.event.signature_slot'))->boolean()
                    ->state(fn (Event $record) => $record->org_quota_at !== null),
            ]);
    }
}
