<?php

namespace App\Filament\Resources\Organizations\RelationManagers;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The organization's team (read-only: the owner manages it at /org/{org}/team).
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans('org.admin.members');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (User $record) => UserResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label(trans('org.create.name'))->weight('bold')->description(fn (User $record) => $record->email),
                TextColumn::make('pivot.role')->label(trans('org.admin.role'))
                    ->formatStateUsing(fn (?string $state) => $state ? trans("org.roles.{$state}") : null)
                    ->badge()->color(fn (?string $state) => $state === 'owner' ? 'warning' : 'gray'),
                TextColumn::make('pivot.created_at')->label(trans('org.admin.created'))->dateTime(),
            ]);
    }
}
