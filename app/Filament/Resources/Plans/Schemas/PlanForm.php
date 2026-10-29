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
                    ->label(trans('admin.catalog.id'))
                    ->required()
                    ->alphaDash()
                    ->maxLength(40)
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit'),
                TextInput::make('name')
                    ->label(trans('admin.catalog.name'))
                    ->required(),
                TextInput::make('price_cents')
                    ->label(trans('admin.plans.usd_price'))
                    ->helperText(trans('app.admin.addons.usd_help'))
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
                TextInput::make('currency')
                    ->label(trans('app.checkout.currency'))
                    ->required()
                    ->length(3)
                    ->default('USD'),
                Select::make('interval')
                    ->label(trans('admin.plans.interval'))
                    ->options(['month' => trans('admin.plans.intervals.month'), 'year' => trans('admin.plans.intervals.year')])
                    ->placeholder(trans('admin.plans.intervals.event')),
                Textarea::make('limits')
                    ->label(trans('admin.plans.limits'))
                    ->helperText('JSON: {"guests": 300, "storage_days": 30, "features": ["rsvp", "personal_links"]}')
                    ->rows(6)
                    ->required()
                    ->json()
                    ->formatStateUsing(fn ($state) => json_encode($state ?? ['features' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                    ->dehydrateStateUsing(fn (?string $state) => json_decode($state ?? '{}', true))
                    ->extraInputAttributes(['dir' => 'ltr']),
                Toggle::make('active')
                    ->label(trans('admin.plans.active'))
                    ->default(true),
            ]);
    }
}
