<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Filament\Resources\Events\EventActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Entitlement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Features unlocked for the event beyond its plan: bought add-ons and admin
 * grants. Granting and revoking go through OrderFulfillment (audit-logged).
 */
class EntitlementsRelationManager extends RelationManager
{
    protected static string $relationship = 'entitlements';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans('admin.entitlements.plural');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('feature')->label(trans('admin.entitlements.feature'))
                    ->formatStateUsing(fn (string $state) => EventActions::featureLabel($state))
                    ->badge(),
                TextColumn::make('source')->label(trans('admin.entitlements.source'))
                    ->state(fn (Entitlement $record) => $record->source_order_id ? ($record->sourceOrder?->code ?? '—') : trans('admin.entitlements.manual'))
                    ->url(fn (Entitlement $record) => $record->sourceOrder ? OrderResource::getUrl('view', ['record' => $record->sourceOrder]) : null),
                TextColumn::make('until')->label(trans('admin.entitlements.until'))
                    ->dateTime()
                    ->placeholder(trans('admin.entitlements.forever')),
                TextColumn::make('state')->label(trans('app.admin.orders.status'))
                    ->state(fn (Entitlement $record) => $record->until === null || $record->until->isFuture() ? 'active' : 'ended')
                    ->formatStateUsing(fn (string $state) => trans("admin.entitlements.states.{$state}"))
                    ->badge()
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('created_at')->label(trans('app.payments.created'))->dateTime(),
            ])
            ->headerActions([
                EventActions::grant()->record(fn () => $this->getOwnerRecord()),
            ])
            ->recordActions([
                EventActions::revoke(),
            ]);
    }
}
