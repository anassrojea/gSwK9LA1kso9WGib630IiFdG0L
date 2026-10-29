<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Orders are created by checkout; admins only correct them here. The
 * Approve / Reject transfer actions arrive with the payments milestone.
 */
class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->disabled(),
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->disabled(),
                Select::make('event_id')
                    ->relationship('event', 'title')
                    ->searchable(),
                TextInput::make('total_cents')
                    ->numeric()
                    ->disabled(),
                TextInput::make('currency')
                    ->disabled(),
                Select::make('method')
                    ->options(array_combine(Order::METHODS, Order::METHODS))
                    ->disabled(),
                Select::make('status')
                    ->options(array_combine(Order::STATUSES, Order::STATUSES))
                    ->required(),
                DateTimePicker::make('expires_at'),
                DateTimePicker::make('paid_at'),
            ]);
    }
}
