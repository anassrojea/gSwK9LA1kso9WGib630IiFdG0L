<?php

namespace App\Filament\Resources\MusicTracks;

use App\Filament\AdminNavigation;
use App\Filament\Resources\MusicTracks\Pages\ManageMusicTracks;
use App\Models\AuditLog;
use App\Models\MusicTrack;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Royalty-free music for highlight videos (phase 8D). The app ships no music:
 * the admin uploads licensed tracks (noting where each comes from), hosts
 * pick one or none. Files go on the media disk under music/.
 */
class MusicTrackResource extends Resource
{
    protected static ?string $model = MusicTrack::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMusicalNote;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Catalog;

    protected static ?int $navigationSort = 8;

    public static function getModelLabel(): string
    {
        return trans('keepsakes.admin.tracks.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('keepsakes.admin.tracks.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label(trans('keepsakes.admin.tracks.title'))->required()->maxLength(120),
            TextInput::make('artist')->label(trans('keepsakes.admin.tracks.artist'))->maxLength(120),
            TextInput::make('license')->label(trans('keepsakes.admin.tracks.license'))->helperText(trans('keepsakes.admin.tracks.license_help'))->required()->maxLength(255),
            FileUpload::make('storage_key')
                ->label(trans('keepsakes.admin.tracks.file'))
                ->disk(config('filesystems.media_disk', 'public'))
                ->directory('music')
                ->visibility('private')
                ->acceptedFileTypes(['audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac'])
                ->maxSize(20 * 1024)
                ->required(),
            TextInput::make('sort')->label(trans('keepsakes.admin.tracks.sort'))->integer()->default(0),
            Toggle::make('active')->label(trans('keepsakes.admin.tracks.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('title')->label(trans('keepsakes.admin.tracks.title'))->searchable(),
                TextColumn::make('artist')->label(trans('keepsakes.admin.tracks.artist')),
                TextColumn::make('license')->label(trans('keepsakes.admin.tracks.license'))->limit(40),
                ToggleColumn::make('active')->label(trans('keepsakes.admin.tracks.active')),
            ])
            ->recordActions([
                EditAction::make()->after(fn (MusicTrack $record) => AuditLog::record('music_track.updated', $record, $record->only(['title', 'license', 'active']))),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMusicTracks::route('/'),
        ];
    }
}
