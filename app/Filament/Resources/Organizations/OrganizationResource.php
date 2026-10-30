<?php

namespace App\Filament\Resources\Organizations;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Resources\Organizations\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Organizations\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Organizations\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Organizations\RelationManagers\SubscriptionsRelationManager;
use App\Models\Organization;
use App\Models\OrgSubscription;
use App\Services\Digits;
use App\Services\Org\OrgPlans;
use App\Services\Org\OrgQuota;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Venues, planners and companies (phase 8A), read-only: members, the
 * subscription and its quota, events, orders. The admin's only changes are
 * the audit-logged actions in OrganizationActions (extend or cancel the
 * subscription) and approving transfer renewals with the usual order
 * approval (OrganizationActions reuses App\Filament\Resources\Orders\OrderActions).
 */
class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Customers;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public const STATUS_COLORS = ['trialing' => 'info', 'active' => 'success', 'past_due' => 'warning', 'canceled' => 'gray', 'expired' => 'danger'];

    public static function getModelLabel(): string
    {
        return trans('org.admin.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('org.admin.plural');
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('subscription')->withCount(['events', 'memberships']))
            ->columns([
                TextColumn::make('name')->label(trans('org.create.name'))->searchable()->weight('bold')
                    ->description(fn (Organization $record) => trans("org.types.{$record->type}")),
                TextColumn::make('city')->label(trans('org.admin.city'))->placeholder('—')->toggleable(),
                TextColumn::make('subscription.plan_id')->label(trans('org.admin.plan'))
                    ->formatStateUsing(fn (?string $state) => $state ? OrgPlans::lineName($state, app()->getLocale()) : null)
                    ->placeholder(trans('org.admin.none'))
                    ->badge()->color('info'),
                TextColumn::make('subscription.status')->label(trans('org.admin.status'))
                    ->formatStateUsing(fn (?string $state) => $state ? trans("org.billing.statuses.{$state}") : null)
                    ->placeholder('—')
                    ->badge()->color(fn (?string $state) => self::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('subscription.current_period_end')->label(trans('org.admin.period_end'))->date()->placeholder('—'),
                TextColumn::make('memberships_count')->label(trans('org.admin.members')),
                TextColumn::make('events_count')->label(trans('org.admin.events'))->sortable(),
                TextColumn::make('created_at')->label(trans('org.admin.created'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->label(trans('org.admin.type'))
                    ->options(collect(Organization::TYPES)->mapWithKeys(fn ($t) => [$t => trans("org.types.{$t}")])->all()),
                SelectFilter::make('status')->label(trans('org.admin.status'))
                    ->options(collect(OrgSubscription::STATUSES)->mapWithKeys(fn ($s) => [$s => trans("org.billing.statuses.{$s}")])->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('subscriptions', fn ($q) => $q->where('status', $data['value']))
                        : $query),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $locale = app()->getLocale();

        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Section::make(trans('org.settings.profile'))
                ->columnSpan(['default' => 1, 'lg' => 2])
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextEntry::make('name')->label(trans('org.create.name'))->weight('bold')->size('lg'),
                    TextEntry::make('type')->label(trans('org.admin.type'))->formatStateUsing(fn (string $state) => trans("org.types.{$state}"))->badge(),
                    TextEntry::make('owner')->label(trans('org.admin.owner'))
                        ->state(fn (Organization $record) => $record->owner()?->name)
                        ->helperText(fn (Organization $record) => $record->owner()?->email)
                        ->placeholder('—'),
                    TextEntry::make('city')->label(trans('org.admin.city'))->placeholder('—'),
                    TextEntry::make('contact_phone')->label(trans('org.create.phone'))->placeholder('—')->copyable()->extraAttributes(['dir' => 'ltr']),
                    TextEntry::make('contact_email')->label(trans('org.create.email'))->placeholder('—')->copyable(),
                    ImageEntry::make('logo_url')->label(trans('org.create.logo'))->height(48)->placeholder('—'),
                    TextEntry::make('white_label')->label(trans('org.settings.white_label'))
                        ->state(fn (Organization $record) => collect($record->white_label ?? [])
                            ->map(fn ($v, $k) => trans('org.settings.'.$k).': '.$v)
                            ->values()->all() ?: ['—'])
                        ->listWithLineBreaks(),
                    TextEntry::make('created_at')->label(trans('org.admin.created'))->dateTime(),
                ]),
            Section::make(trans('org.admin.subscription'))
                ->columnSpan(1)
                ->schema([
                    TextEntry::make('sub_plan')->label(trans('org.admin.plan'))
                        ->state(fn (Organization $record) => ($s = $record->subscription()->first()) ? OrgPlans::lineName($s->plan_id, $locale) : trans('org.admin.none'))
                        ->weight('bold'),
                    TextEntry::make('sub_status')->label(trans('org.admin.status'))
                        ->state(fn (Organization $record) => $record->subscription()->first()?->status ?? $record->subscriptions()->first()?->status)
                        ->formatStateUsing(fn (?string $state) => $state ? trans("org.billing.statuses.{$state}") : null)
                        ->placeholder('—')
                        ->badge()->color(fn (?string $state) => self::STATUS_COLORS[$state] ?? 'gray'),
                    TextEntry::make('sub_provider')->label(trans('org.admin.provider'))
                        ->state(fn (Organization $record) => ($p = $record->subscription()->first()?->provider) ? trans("org.billing.providers.{$p}") : null)
                        ->placeholder('—'),
                    TextEntry::make('sub_end')->label(trans('org.admin.period_end'))
                        ->state(fn (Organization $record) => $record->subscription()->first()?->current_period_end)
                        ->dateTime()->placeholder('—'),
                    TextEntry::make('quota')->label(trans('org.admin.quota'))
                        ->state(function (Organization $record) use ($locale) {
                            $q = app(OrgQuota::class)->summary($record);

                            return $q['active'] ? Digits::localize(trans('org.admin.quota_value', ['used' => $q['used'], 'limit' => $q['limit']]), $locale) : trans('org.admin.none');
                        })
                        ->extraAttributes(['data-org-quota' => true]),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            SubscriptionsRelationManager::class,
            OrdersRelationManager::class,
            EventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'view' => ViewOrganization::route('/{record}'),
        ];
    }
}
