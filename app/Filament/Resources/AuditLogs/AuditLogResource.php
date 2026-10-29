<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Who approved what and when (plan sections 4.3 and 20). Read-only.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 90;

    public static function getModelLabel(): string
    {
        return trans('app.admin.audit.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('app.admin.audit.plural');
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('at')->label(trans('app.admin.audit.at'))->dateTime(),
            TextEntry::make('actor.name')->label(trans('app.admin.audit.actor'))->placeholder(trans('app.admin.audit.system')),
            TextEntry::make('action')->label(trans('app.admin.audit.action')),
            TextEntry::make('entity')->label(trans('app.admin.audit.entity'))
                ->state(fn (AuditLog $record) => $record->entity.' '.$record->entity_id),
            TextEntry::make('data')->label(trans('app.admin.audit.data'))
                ->state(fn (AuditLog $record) => json_encode($record->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->fontFamily('mono')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('at', 'desc')
            ->columns([
                TextColumn::make('at')->label(trans('app.admin.audit.at'))->dateTime()->sortable(),
                TextColumn::make('actor.name')->label(trans('app.admin.audit.actor'))->placeholder(trans('app.admin.audit.system'))->searchable(),
                TextColumn::make('action')->label(trans('app.admin.audit.action'))->badge()->searchable(),
                TextColumn::make('entity')->label(trans('app.admin.audit.entity')),
                TextColumn::make('entity_id')->label('ID')->searchable()->limit(12)->copyable(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label(trans('app.admin.audit.action'))
                    ->options(fn () => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all()),
                SelectFilter::make('entity')
                    ->label(trans('app.admin.audit.entity'))
                    ->options(fn () => AuditLog::query()->distinct()->orderBy('entity')->pluck('entity', 'entity')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }
}
