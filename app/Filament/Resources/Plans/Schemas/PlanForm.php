<?php

namespace App\Filament\Resources\Plans\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('id')
                    ->label('ID')
                    ->required()
                    ->alphaDash()
                    ->maxLength(40)
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit'),
                TextInput::make('name')
                    ->required(),
                TextInput::make('price_cents')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
                TextInput::make('currency')
                    ->required()
                    ->length(3)
                    ->default('USD'),
                Select::make('interval')
                    ->options(['month' => 'Monthly', 'year' => 'Yearly'])
                    ->placeholder('Per event'),
                Textarea::make('limits')
                    ->helperText('JSON: {"guests": 300, "storage_days": 30, "features": ["rsvp", "personal_links"]}')
                    ->rows(6)
                    ->required()
                    ->json()
                    ->formatStateUsing(fn ($state) => json_encode($state ?? ['features' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                    ->dehydrateStateUsing(fn (?string $state) => json_decode($state ?? '{}', true)),
                Toggle::make('active')
                    ->default(true),
            ]);
    }
}
