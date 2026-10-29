<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Services\Payments\Currencies;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'md' => 2])
            ->components([
                TextInput::make('name')
                    ->label(trans('admin.users.name'))
                    ->required(),
                TextInput::make('email')
                    ->label(trans('admin.users.email'))
                    ->email()
                    ->unique(ignoreRecord: true),
                TextInput::make('phone')
                    ->label(trans('admin.users.phone'))
                    ->tel()
                    ->unique(ignoreRecord: true),
                DateTimePicker::make('email_verified_at')
                    ->label(trans('admin.users.email_verified_at')),
                TextInput::make('password')
                    ->label(trans('admin.users.password'))
                    ->password()
                    ->revealable()
                    // keep the current password unless a new one is typed
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->required(fn (string $operation) => $operation === 'create'),
                Select::make('locale')
                    ->label(trans('admin.users.locale'))
                    ->options(['ar' => 'العربية', 'en' => 'English'])
                    ->required()
                    ->default('ar'),
                Select::make('country')
                    ->label(trans('admin.users.country'))
                    ->options(collect(array_keys(config('payments.countries')))->mapWithKeys(fn ($c) => [$c => trans("app.checkout.countries.{$c}")])->all())
                    ->placeholder(trans('app.checkout.countries.other')),
                Select::make('currency')
                    ->label(trans('app.checkout.currency'))
                    ->options(array_combine(Currencies::all(), Currencies::all())),
                // role is not mass assignable; the Create/Edit pages set it explicitly
                Select::make('role')
                    ->label(trans('admin.users.role'))
                    ->options([User::ROLE_USER => trans('admin.users.roles.user'), User::ROLE_ADMIN => trans('admin.users.roles.admin')])
                    ->required()
                    ->default(User::ROLE_USER),
            ]);
    }
}
