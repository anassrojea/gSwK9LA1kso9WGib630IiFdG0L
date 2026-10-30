<?php

namespace App\Filament\Resources\VenueListings;

use App\Filament\AdminNavigation;
use App\Filament\Resources\VenueListings\Pages\ListVenueListings;
use App\Models\AuditLog;
use App\Models\VenueProfile;
use App\Services\Venues\VenueDirectory;
use App\Services\Venues\VenueFeatures;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Venue directory listings (phase 8B, plan section 13.1): venues ask to be
 * listed; the admin approves, hides or features them. A listing is public
 * once approved and while the venue's plan includes directory_listing.
 * Each decision goes to audit_log.
 */
class VenueListingResource extends Resource
{
    protected static ?string $model = VenueProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Customers;

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return trans('venues.admin.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('venues.admin.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = VenueProfile::query()->where('listed', true)->where('status', VenueProfile::STATUS_PENDING)->count();

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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['organization.plan']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('organization.name')->label(trans('venues.admin.venue'))->searchable()->weight('bold'),
                TextColumn::make('slug')->label(trans('venues.dashboard.slug'))->searchable()->copyable(),
                TextColumn::make('city')->label(trans('venues.dashboard.city'))->formatStateUsing(fn (string $state) => trans("venues.cities.{$state}")),
                TextColumn::make('status')->label(trans('venues.admin.status'))->badge()
                    ->formatStateUsing(fn (string $state) => trans("venues.dashboard.status.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        VenueProfile::STATUS_APPROVED => 'success',
                        VenueProfile::STATUS_PENDING => 'warning',
                        VenueProfile::STATUS_HIDDEN => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('listed')->label(trans('venues.admin.listed'))->boolean(),
                IconColumn::make('featured')->label(trans('venues.admin.featured'))->boolean(),
                IconColumn::make('plan')->label(trans('venues.admin.plan'))->boolean()
                    ->state(fn (VenueProfile $record) => app(VenueFeatures::class)->has($record->organization, VenueFeatures::DIRECTORY_LISTING)),
                TextColumn::make('updated_at')->label(trans('venues.admin.updated'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(trans('venues.admin.status'))
                    ->options(collect(VenueProfile::STATUSES)->mapWithKeys(fn ($s) => [$s => trans("venues.dashboard.status.{$s}")])->all()),
                TernaryFilter::make('featured')->label(trans('venues.admin.featured')),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(trans('venues.admin.open'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(fn (VenueProfile $record) => VenueDirectory::url($record, 'ar'), shouldOpenInNewTab: true),
                Action::make('approve')
                    ->label(trans('venues.admin.approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (VenueProfile $record) => $record->status !== VenueProfile::STATUS_APPROVED)
                    ->requiresConfirmation()
                    ->action(fn (VenueProfile $record) => static::decide($record, 'approve')),
                Action::make('hide')
                    ->label(trans('venues.admin.hide'))
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('danger')
                    ->visible(fn (VenueProfile $record) => $record->status !== VenueProfile::STATUS_HIDDEN)
                    ->requiresConfirmation()
                    ->action(fn (VenueProfile $record) => static::decide($record, 'hide')),
                Action::make('feature')
                    ->label(fn (VenueProfile $record) => $record->featured ? trans('venues.admin.unfeature') : trans('venues.admin.feature'))
                    ->icon(Heroicon::OutlinedStar)
                    ->color('warning')
                    ->action(fn (VenueProfile $record) => static::decide($record, $record->featured ? 'unfeature' : 'feature')),
            ]);
    }

    /**
     * approve | hide | feature | unfeature, recorded in audit_log.
     */
    public static function decide(VenueProfile $record, string $decision): void
    {
        $changes = match ($decision) {
            'approve' => ['status' => VenueProfile::STATUS_APPROVED, 'approved_at' => now(), 'approved_by' => auth()->id()],
            'hide' => ['status' => VenueProfile::STATUS_HIDDEN, 'featured' => false],
            'feature' => ['featured' => true],
            'unfeature' => ['featured' => false],
        };
        $record->update($changes);
        AuditLog::record("venue.{$decision}", $record, ['slug' => $record->slug, 'org_id' => $record->org_id]);

        Notification::make()->title(trans("venues.admin.done_{$decision}"))->success()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVenueListings::route('/'),
        ];
    }
}
