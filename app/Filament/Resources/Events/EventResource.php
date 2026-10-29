<?php

namespace App\Filament\Resources\Events;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\EntitlementsRelationManager;
use App\Filament\Resources\Events\RelationManagers\HouseholdsRelationManager;
use App\Filament\Resources\Events\Schemas\EventInfolist;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Filament\Resources\Users\RelationManagers\OrdersRelationManager;
use App\Models\Event;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Customers' events, read-only: the event page is "open as the customer"
 * (plan section 4.3), showing the host's dashboard numbers, the invitation
 * as guests get it, guests and RSVPs, orders and entitlements, with no way
 * to edit them. There is no create or edit page: the plan, publishing and
 * guest limits change only through orders and entitlements (OrderFulfillment,
 * PublishGate), plus the audit-logged actions in EventActions.
 */
class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Customers;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getModelLabel(): string
    {
        return trans('admin.events.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('admin.events.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return EventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            HouseholdsRelationManager::class,
            OrdersRelationManager::class,
            EntitlementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'view' => ViewEvent::route('/{record}'),
        ];
    }
}
