<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Marketplace\Pages\ListVendorListings;
use App\Models\VendorListing;
use App\Services\Marketplace\MarketplaceUrl;
use App\Services\Marketplace\VendorListings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Vendor listings (phase 8C): approve or reject new ones, hide one that shouldn't show,
 * and feature one by hand (e.g. a launch partner) in one of its cities.
 */
class VendorListingResource extends Resource
{
    protected static ?string $model = VendorListing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Marketplace;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'marketplace/vendors';

    public static function getModelLabel(): string
    {
        return trans('vendors.admin.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('vendors.admin.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = VendorListing::query()->where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user')->withMax(['features as featured_until' => fn ($q) => $q->active()], 'ends_at');
    }

    public static function table(Table $table): Table
    {
        $locale = app()->getLocale();

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make($locale === 'ar' ? 'name_ar' : 'name_en')->label(trans('vendors.admin.name'))->searchable(['name_ar', 'name_en', 'slug'])->description(fn (VendorListing $r) => $r->user?->email),
                TextColumn::make('category')->label(trans('vendors.public.category'))->formatStateUsing(fn (string $state) => trans("vendors.categories.{$state}"))->badge(),
                TextColumn::make('cities')->label(trans('vendors.public.city'))->state(fn (VendorListing $r) => collect($r->cities ?? [])->map(fn ($c) => trans("vendors.cities.{$c}"))->implode(', '))->wrap(),
                TextColumn::make('status')->label(trans('marketplace.admin.status'))->badge()
                    ->formatStateUsing(fn (string $state) => trans("vendors.statuses.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('featured_until')->label(trans('vendors.admin.featured_until'))->dateTime()->placeholder('—'),
                TextColumn::make('created_at')->label(trans('marketplace.admin.applied'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(trans('marketplace.admin.status'))->options(MarketplaceActions::options('vendors.statuses', VendorListing::STATUSES)),
                SelectFilter::make('category')->label(trans('vendors.public.category'))->options(MarketplaceActions::options('vendors.categories', config('marketplace.vendor_categories'))),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(trans('vendors.admin.open'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->visible(fn (VendorListing $r) => $r->isPublic())
                    ->url(fn (VendorListing $r) => MarketplaceUrl::path('vendor', $locale, $r->slug), shouldOpenInNewTab: true),
                Action::make('approve')
                    ->label(trans('marketplace.admin.approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (VendorListing $r) => $r->status !== 'approved')
                    ->requiresConfirmation()
                    ->action(fn (VendorListing $record, Action $action) => MarketplaceActions::run($action, fn () => app(VendorListings::class)->approve($record, auth()->user()), trans('marketplace.admin.done'))),
                ActionGroup::make([
                    Action::make('feature')
                        ->label(trans('vendors.admin.feature'))
                        ->icon(Heroicon::OutlinedStar)
                        ->visible(fn (VendorListing $r) => $r->isPublic())
                        ->schema(fn (VendorListing $record) => [
                            Select::make('city')->label(trans('vendors.public.city'))->required()
                                ->options(collect($record->cities ?? [])->mapWithKeys(fn ($c) => [$c => trans("vendors.cities.{$c}")])->all()),
                            TextInput::make('days')->label(trans('vendors.admin.days'))->integer()->minValue(1)->maxValue(365)->default((int) config('marketplace.feature.days', 30))->required(),
                        ])
                        ->action(fn (VendorListing $record, array $data, Action $action) => MarketplaceActions::run($action, fn () => app(VendorListings::class)->feature($record, $data['city'], (int) $data['days'], null, auth()->user()), trans('marketplace.admin.done'))),
                    Action::make('reject')
                        ->label(trans('marketplace.admin.reject'))
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('danger')
                        ->visible(fn (VendorListing $r) => $r->status === 'pending')
                        ->schema([Textarea::make('reason')->label(trans('marketplace.admin.reason'))->required()->rows(2)->maxLength(500)])
                        ->action(fn (VendorListing $record, array $data, Action $action) => MarketplaceActions::run($action, fn () => app(VendorListings::class)->reject($record, auth()->user(), $data['reason']), trans('marketplace.admin.done'))),
                    Action::make('hide')
                        ->label(trans('vendors.admin.hide'))
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->color('danger')
                        ->visible(fn (VendorListing $r) => $r->status === 'approved')
                        ->requiresConfirmation()
                        ->schema([Textarea::make('reason')->label(trans('marketplace.admin.reason'))->rows(2)->maxLength(500)])
                        ->action(fn (VendorListing $record, array $data, Action $action) => MarketplaceActions::run($action, fn () => app(VendorListings::class)->hide($record, auth()->user(), $data['reason'] ?? null), trans('marketplace.admin.done'))),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListVendorListings::route('/')];
    }
}
