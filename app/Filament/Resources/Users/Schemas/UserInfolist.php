<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Resources\Users\UserResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Digits;
use App\Services\Payments\Currencies;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * The customer page: profile, sign-in methods, what they paid (per currency,
 * never added across currencies) and whether they asked to be deleted.
 */
class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Section::make(trans('admin.users.profile'))
                ->columnSpan(['default' => 1, 'lg' => 2])
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextEntry::make('name')->label(trans('admin.users.name'))->weight('bold')->size('lg'),
                    TextEntry::make('role')->label(trans('admin.users.role'))
                        ->formatStateUsing(fn (string $state) => trans("admin.users.roles.{$state}"))
                        ->badge()->color(fn (string $state) => $state === User::ROLE_ADMIN ? 'danger' : 'gray'),
                    TextEntry::make('email')->label(trans('admin.users.email'))->placeholder('—')->copyable(),
                    TextEntry::make('phone')->label(trans('admin.users.phone'))->placeholder('—')->copyable(),
                    TextEntry::make('sign_in')->label(trans('admin.users.sign_in'))
                        ->state(fn (User $record) => UserResource::signInMethods($record) ?: ['none'])
                        ->formatStateUsing(fn (string $state) => trans("admin.users.methods.{$state}"))
                        ->badge()->color('info'),
                    TextEntry::make('email_verified_at')->label(trans('admin.users.email_verified_at'))->dateTime()->placeholder(trans('admin.users.not_verified')),
                    TextEntry::make('locale')->label(trans('admin.users.locale'))->formatStateUsing(fn (?string $state) => $state === 'en' ? 'English' : 'العربية'),
                    TextEntry::make('country')->label(trans('admin.users.country'))
                        ->formatStateUsing(fn (?string $state) => $state ? trans("app.checkout.countries.{$state}") : null)
                        ->placeholder('—'),
                    TextEntry::make('currency')->label(trans('app.checkout.currency'))->placeholder('—'),
                    TextEntry::make('created_at')->label(trans('admin.users.joined'))->dateTime(),
                ]),
            Section::make(trans('admin.users.money'))
                ->columnSpan(1)
                ->schema([
                    TextEntry::make('paid')->label(trans('admin.users.total_paid'))
                        ->state(fn (User $record) => static::paidPerCurrency($record) ?: [trans('admin.users.nothing_paid')])
                        ->listWithLineBreaks()
                        ->weight('bold'),
                    TextEntry::make('orders_count')->label(trans('admin.users.orders'))->state(fn (User $record) => Digits::localize($record->orders()->count(), app()->getLocale())),
                    TextEntry::make('events_count')->label(trans('admin.users.events'))->state(fn (User $record) => Digits::localize($record->events()->count(), app()->getLocale())),
                    TextEntry::make('deletion_requested_at')->label(trans('admin.users.deletion_requested'))
                        ->visible(fn () => UserResource::tracksDeletionRequests())
                        ->dateTime()
                        ->helperText(fn (User $record) => $record->deletion_requested_at
                            ? trans('admin.users.deletion_scheduled', ['date' => Carbon::parse($record->deletion_requested_at)->addDays((int) config('account.deletion_grace_days', 14))->translatedFormat('j F Y')])
                            : null)
                        ->badge()->color('danger')
                        ->placeholder(trans('admin.common.no')),
                ]),
        ]);
    }

    /**
     * "١٠٩ ر.س.‏", "٣٥٠٬٠٠٠ ل.س.‏": one line per currency.
     *
     * @return list<string>
     */
    public static function paidPerCurrency(User $user): array
    {
        return Order::query()
            ->where('user_id', $user->getKey())
            ->fulfilled()
            ->groupBy('currency')
            ->orderBy('currency')
            ->selectRaw('currency, sum(total_cents) as total')
            ->get()
            ->map(fn ($row) => Currencies::format((int) $row->total, $row->currency, app()->getLocale()))
            ->all();
    }
}
