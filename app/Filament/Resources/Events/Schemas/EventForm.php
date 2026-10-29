<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Models\Event;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                Select::make('occasion')
                    ->options(array_combine(Event::OCCASIONS, Event::OCCASIONS))
                    ->required(),
                Select::make('owner_id')
                    ->relationship('owner', 'name')
                    ->searchable(),
                Select::make('org_id')
                    ->relationship('organization', 'name')
                    ->searchable(),
                DateTimePicker::make('starts_at'),
                TextInput::make('timezone')
                    ->required()
                    ->default('Asia/Riyadh'),
                TextInput::make('venue_name'),
                TextInput::make('address'),
                TextInput::make('lat')
                    ->numeric(),
                TextInput::make('lng')
                    ->numeric(),
                Select::make('hall_layout_id')
                    ->relationship('hallLayout', 'name'),
                Select::make('status')
                    ->options(array_combine(Event::STATUSES, Event::STATUSES))
                    ->required()
                    ->default('draft'),
                Select::make('plan_id')
                    ->relationship('plan', 'name'),
                TextInput::make('guest_limit')
                    ->numeric()
                    ->helperText('Overrides the plan limit when set'),
                DatePicker::make('storage_until'),
            ]);
    }
}
