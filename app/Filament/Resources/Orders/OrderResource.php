<?php

namespace App\Filament\Resources\Orders;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Orders\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\TransferReceiptsRelationManager;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Orders and transfer approvals (plan sections 4.3 and 12.2). Orders are
 * created by checkout and only change through the actions here (Approve,
 * Reject, Mark refunded), which go through OrderFulfillment and the audit log.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'code';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Sales;

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return trans('app.admin.orders.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('app.admin.orders.plural');
    }

    /**
     * Receipts waiting for a decision (plan section 4.3: "with count badge").
     */
    public static function getNavigationBadge(): ?string
    {
        $count = Order::query()->where('status', 'receipt_received')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return trans('app.admin.orders.waiting');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code'];
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TransferReceiptsRelationManager::class,
            PaymentsRelationManager::class,
            InvoicesRelationManager::class,
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
