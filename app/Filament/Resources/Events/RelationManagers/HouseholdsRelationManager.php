<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Models\Household;
use App\Models\OpenLink;
use App\Services\Dashboard\GuestBoard;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Guests and RSVPs of the event, as the host's list shows them (read-only;
 * personal links are never shown here, so the admin can't open them as a guest).
 */
class HouseholdsRelationManager extends RelationManager
{
    protected static string $relationship = 'households';

    public const STATUS_COLORS = [
        GuestBoard::NOT_OPENED => 'gray',
        GuestBoard::OPENED => 'info',
        GuestBoard::ATTENDING => 'success',
        GuestBoard::DECLINED => 'danger',
    ];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans('admin.events.guests');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('rsvp'))
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('display_name')->label(trans('admin.events.family'))->searchable()->weight('bold'),
                TextColumn::make('phone')->label(trans('admin.users.phone'))->placeholder('—')->toggleable(),
                TextColumn::make('seats_allowed')->label(trans('admin.events.stats.seats')),
                TextColumn::make('status')->label(trans('admin.events.rsvp'))
                    ->state(fn (Household $record) => GuestBoard::status($record))
                    ->formatStateUsing(fn (string $state) => trans("app.dashboard.status.{$state}"))
                    ->badge()
                    ->color(fn (string $state) => self::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('rsvp.people_count')->label(trans('admin.events.people'))->placeholder('—'),
                TextColumn::make('rsvp.message')->label(trans('admin.events.message'))->limit(50)->placeholder('—')->wrap()->toggleable(),
                TextColumn::make('open_count')->label(trans('admin.events.opens'))->toggleable(),
                TextColumn::make('tags')->label(trans('admin.events.tags'))
                    ->formatStateUsing(fn (string $state) => $state === OpenLink::TAG ? trans('admin.events.open_link') : $state)
                    ->badge()->color('gray')->toggleable(),
                TextColumn::make('rsvp.responded_at')->label(trans('admin.events.replied_at'))->since()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('rsvp_state')
                    ->label(trans('admin.events.rsvp'))
                    ->options(collect(GuestBoard::STATUSES)->mapWithKeys(fn ($s) => [$s => trans("app.dashboard.status.{$s}")])->all())
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        GuestBoard::ATTENDING => $query->whereHas('rsvp', fn ($q) => $q->where('attending', true)),
                        GuestBoard::DECLINED => $query->whereHas('rsvp', fn ($q) => $q->where('attending', false)),
                        GuestBoard::OPENED => $query->whereDoesntHave('rsvp')->whereNotNull('first_opened_at'),
                        GuestBoard::NOT_OPENED => $query->whereDoesntHave('rsvp')->whereNull('first_opened_at'),
                        default => $query,
                    }),
            ]);
    }
}
