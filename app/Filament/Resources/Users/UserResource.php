<?php

namespace App\Filament\Resources\Users;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Users\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema as DbSchema;
use UnitEnum;

/**
 * Customers (plan section 4.3): who they are, how they sign in, their events
 * and orders and what they paid per currency. The event pages are the
 * read-only "open as the customer" view (App\Filament\Resources\Events).
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Customers;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    private static ?bool $deletionColumn = null;

    public static function getModelLabel(): string
    {
        return trans('admin.users.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('admin.users.plural');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'phone'];
    }

    /**
     * users.deletion_requested_at arrives with the account area (phase 5A); until then the flag is hidden.
     */
    public static function tracksDeletionRequests(): bool
    {
        return static::$deletionColumn ??= DbSchema::hasColumn('users', 'deletion_requested_at');
    }

    /**
     * @return list<string> password, google
     */
    public static function signInMethods(User $user): array
    {
        return array_values(array_filter([
            $user->getAuthPassword() ? 'password' : null,
            $user->google_id ? 'google' : null,
        ]));
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
            OrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
