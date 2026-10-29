<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->unique(ignoreRecord: true),
                TextInput::make('phone')
                    ->tel()
                    ->unique(ignoreRecord: true),
                DateTimePicker::make('email_verified_at'),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    // keep the current password unless a new one is typed
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->required(fn (string $operation) => $operation === 'create'),
                Select::make('locale')
                    ->options(['ar' => 'العربية', 'en' => 'English'])
                    ->required()
                    ->default('ar'),
                // role is not mass assignable; the Create/Edit pages set it explicitly
                Select::make('role')
                    ->options([User::ROLE_USER => 'User', User::ROLE_ADMIN => 'Admin'])
                    ->required()
                    ->default(User::ROLE_USER),
            ]);
    }
}
