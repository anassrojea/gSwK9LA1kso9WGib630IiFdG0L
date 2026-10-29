<?php

namespace App\Filament\Resources\Events\Tables;

use App\Filament\Resources\Events\EventActions;
use App\Filament\Resources\Templates\TemplateResource;
use App\Models\Event;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Events, read-only: the plan, publishing and storage change only through
 * orders, entitlements and the actions in EventActions.
 */
class EventsTable
{
    public const STATUS_COLORS = ['draft' => 'gray', 'active' => 'success', 'ended' => 'info', 'archived' => 'gray'];

    public static function configure(Table $table, bool $withOwner = true): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['owner', 'invitation'])->withCount('households'))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('title')
                    ->label(trans('admin.events.title'))
                    ->description(fn (Event $record) => trans("app.wizard.occasions.{$record->occasion}"))
                    ->searchable()
                    ->limit(40)
                    ->weight('bold'),
                TextColumn::make('owner.name')
                    ->label(trans('app.admin.orders.customer'))
                    ->description(fn (Event $record) => $record->owner?->email)
                    ->placeholder(trans('admin.events.guest_draft'))
                    ->searchable()
                    ->visible($withOwner),
                TextColumn::make('status')
                    ->label(trans('app.admin.orders.status'))
                    ->formatStateUsing(fn (string $state) => trans("app.status.{$state}"))
                    ->badge()
                    ->color(fn (string $state) => self::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('plan_id')
                    ->label(trans('admin.events.plan'))
                    ->formatStateUsing(fn (?string $state) => trans('app.wizard.tiers.'.($state ?? 'free')))
                    ->placeholder(trans('app.wizard.tiers.free'))
                    ->badge()
                    ->color('info'),
                TextColumn::make('invitation.published_at')
                    ->label(trans('admin.events.published'))
                    ->since()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('households_count')
                    ->label(trans('admin.events.families'))
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label(trans('admin.events.starts_at'))
                    ->date()
                    ->sortable(),
                TextColumn::make('storage_until')
                    ->label(trans('admin.events.storage_until'))
                    ->date()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(trans('app.payments.created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(trans('app.admin.orders.status'))
                    ->options(collect(Event::STATUSES)->mapWithKeys(fn ($s) => [$s => trans("app.status.{$s}")])->all()),
                SelectFilter::make('occasion')
                    ->label(trans('admin.templates.occasion'))
                    ->options(TemplateResource::occasionOptions()),
                SelectFilter::make('plan_id')
                    ->label(trans('admin.events.plan'))
                    ->relationship('plan', 'name'),
                TernaryFilter::make('published')
                    ->label(trans('admin.events.published'))
                    ->queries(
                        true: fn (Builder $q) => $q->whereHas('invitation', fn ($i) => $i->whereNotNull('published_at')),
                        false: fn (Builder $q) => $q->whereDoesntHave('invitation', fn ($i) => $i->whereNotNull('published_at')),
                    ),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(trans('admin.events.open_as_customer'))
                    ->icon(Heroicon::OutlinedEye),
                ActionGroup::make([
                    EventActions::preview(),
                    EventActions::extendStorage(),
                    EventActions::grant(),
                ]),
            ]);
    }
}
