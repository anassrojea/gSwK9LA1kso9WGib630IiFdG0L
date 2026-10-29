<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['events', 'orders' => fn ($q) => $q->fulfilled()]))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')
                    ->label(trans('admin.users.name'))
                    ->description(fn (User $record) => $record->email ?? $record->phone)
                    ->searchable(['name', 'email', 'phone'])
                    ->weight('bold'),
                TextColumn::make('phone')
                    ->label(trans('admin.users.phone'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sign_in')
                    ->label(trans('admin.users.sign_in'))
                    ->state(fn (User $record) => UserResource::signInMethods($record))
                    ->formatStateUsing(fn (string $state) => trans("admin.users.methods.{$state}"))
                    ->badge()
                    ->color('info'),
                TextColumn::make('locale')
                    ->label(trans('admin.users.locale'))
                    ->formatStateUsing(fn (?string $state) => strtoupper((string) $state))
                    ->toggleable(),
                TextColumn::make('country')
                    ->label(trans('admin.users.country'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('events_count')
                    ->label(trans('admin.users.events'))
                    ->sortable(),
                TextColumn::make('orders_count')
                    ->label(trans('admin.users.paid_orders'))
                    ->sortable(),
                TextColumn::make('role')
                    ->label(trans('admin.users.role'))
                    ->formatStateUsing(fn (string $state) => trans("admin.users.roles.{$state}"))
                    ->badge()
                    ->color(fn (string $state) => $state === User::ROLE_ADMIN ? 'danger' : 'gray'),
                TextColumn::make('deletion_requested_at')
                    ->label(trans('admin.users.deletion_requested'))
                    ->visible(fn () => UserResource::tracksDeletionRequests())
                    ->since()
                    ->badge()
                    ->color('danger')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label(trans('admin.users.joined'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label(trans('admin.users.role'))
                    ->options([User::ROLE_USER => trans('admin.users.roles.user'), User::ROLE_ADMIN => trans('admin.users.roles.admin')]),
                SelectFilter::make('locale')
                    ->label(trans('admin.users.locale'))
                    ->options(['ar' => 'العربية', 'en' => 'English']),
                SelectFilter::make('country')
                    ->label(trans('admin.users.country'))
                    ->options(collect(array_keys(config('payments.countries')))->mapWithKeys(fn ($c) => [$c => trans("app.checkout.countries.{$c}")])->all()),
                Filter::make('paid')
                    ->label(trans('admin.users.has_paid'))
                    ->query(fn (Builder $query) => $query->whereHas('orders', fn ($q) => $q->fulfilled())),
                TernaryFilter::make('deletion_requested_at')
                    ->label(trans('admin.users.deletion_requested'))
                    ->nullable()
                    ->visible(fn () => UserResource::tracksDeletionRequests()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
