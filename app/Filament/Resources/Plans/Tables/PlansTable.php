<?php

namespace App\Filament\Resources\Plans\Tables;

use App\Models\Plan;
use App\Services\Payments\Currencies;
use App\Services\Payments\PriceList;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('price_cents')
            ->columns([
                TextColumn::make('id')
                    ->label(trans('admin.catalog.id'))
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('name')
                    ->label(trans('admin.catalog.name'))
                    ->searchable(),
                TextColumn::make('prices')
                    ->label(trans('app.admin.prices.plural'))
                    ->state(fn (Plan $record) => collect(app(PriceList::class)->all($record))
                        ->filter(fn ($amount) => $amount !== null)
                        ->map(fn ($amount, $currency) => Currencies::format($amount, $currency, app()->getLocale()))
                        ->values()
                        ->all())
                    ->listWithLineBreaks(),
                TextColumn::make('limits.guests')
                    ->label(trans('admin.plans.guests'))
                    ->placeholder('∞'),
                TextColumn::make('interval')
                    ->label(trans('admin.plans.interval'))
                    ->formatStateUsing(fn (?string $state) => trans('admin.plans.intervals.'.($state ?? 'event')))
                    ->placeholder(trans('admin.plans.intervals.event')),
                TextColumn::make('events_count')
                    ->label(trans('admin.events.plural'))
                    ->counts('events'),
                IconColumn::make('active')
                    ->label(trans('admin.plans.active'))
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label(trans('app.admin.prices.updated'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
