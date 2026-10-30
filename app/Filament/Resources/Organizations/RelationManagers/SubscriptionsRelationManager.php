<?php

namespace App\Filament\Resources\Organizations\RelationManagers;

use App\Filament\Resources\Organizations\OrganizationResource;
use App\Models\OrgSubscription;
use App\Services\Org\OrgPlans;
use App\Services\Payments\Currencies;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Every subscription the organization had, newest first (read-only).
 */
class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans('org.admin.subscriptions');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('plan_id')->label(trans('org.admin.plan'))->formatStateUsing(fn (string $state) => OrgPlans::lineName($state, app()->getLocale()))->weight('bold'),
                TextColumn::make('status')->label(trans('org.admin.status'))
                    ->formatStateUsing(fn (string $state) => trans("org.billing.statuses.{$state}"))
                    ->badge()->color(fn (string $state) => OrganizationResource::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('provider')->label(trans('org.admin.provider'))->formatStateUsing(fn (string $state) => trans("org.billing.providers.{$state}")),
                TextColumn::make('amount_cents')->label(trans('app.payments.amount'))
                    ->formatStateUsing(fn (OrgSubscription $record) => Currencies::format($record->amount_cents, $record->currency, app()->getLocale())),
                TextColumn::make('current_period_end')->label(trans('org.admin.period_end'))->dateTime()->placeholder('—'),
                IconColumn::make('cancel_at_period_end')->label(trans('org.billing.cancel'))->boolean(),
                TextColumn::make('next_plan_id')->label(trans('org.billing.next_plan', ['plan' => '']))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider_id')->label('ID')->copyable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
