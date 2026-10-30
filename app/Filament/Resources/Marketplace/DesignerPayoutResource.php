<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Marketplace\Pages\ListDesignerPayouts;
use App\Models\DesignerPayout;
use App\Models\DesignerProfile;
use App\Services\Marketplace\DesignerLedger;
use App\Services\Payments\Currencies;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Designer payouts (phase 8C): the monthly statements (marketplace:payout-statements),
 * paid by manual bank transfer and marked paid here with the bank's reference. Marking
 * posts the payout to the designer ledger and is audit-logged (DesignerLedger::markPaid).
 */
class DesignerPayoutResource extends Resource
{
    protected static ?string $model = DesignerPayout::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Marketplace;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'marketplace/payouts';

    public static function getModelLabel(): string
    {
        return trans('marketplace.admin.payouts.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('marketplace.admin.payouts.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = DesignerPayout::query()->where('status', 'pending')->count();

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
        $locale = app()->getLocale();
        $designer = fn (DesignerPayout $p) => DesignerProfile::query()->where('user_id', $p->designer_id)->first();

        return $table
            ->defaultSort('period', 'desc')
            ->columns([
                TextColumn::make('period')->label(trans('marketplace.admin.payouts.period'))->sortable()->fontFamily('mono'),
                TextColumn::make('designer.name')->label(trans('marketplace.admin.review.designer'))
                    ->state(fn (DesignerPayout $p) => $designer($p)?->displayName($locale) ?? $p->designer?->name)
                    ->description(fn (DesignerPayout $p) => $p->designer?->email),
                TextColumn::make('amount_cents')->label(trans('marketplace.admin.payouts.amount'))
                    ->state(fn (DesignerPayout $p) => Currencies::format($p->amount_cents, $p->currency, $locale))
                    ->description(fn (DesignerPayout $p) => trans_choice('marketplace.admin.payouts.sales', $p->sales_count, ['count' => $p->sales_count])),
                TextColumn::make('currency')->label(trans('marketplace.admin.payouts.currency'))->badge(),
                TextColumn::make('status')->label(trans('marketplace.admin.status'))->badge()
                    ->formatStateUsing(fn (string $state) => trans("marketplace.statuses.payout.{$state}"))
                    ->color(fn (string $state) => $state === 'paid' ? 'success' : 'warning'),
                TextColumn::make('reference')->label(trans('marketplace.admin.payouts.reference'))->placeholder('—')->copyable(),
                TextColumn::make('paid_at')->label(trans('marketplace.admin.payouts.paid_at'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(trans('marketplace.admin.status'))->options(MarketplaceActions::options('marketplace.statuses.payout', ['pending', 'paid'])),
                SelectFilter::make('currency')->label(trans('marketplace.admin.payouts.currency'))->options(array_combine(Currencies::all(), Currencies::all())),
            ])
            ->recordActions([
                Action::make('mark_paid')
                    ->label(trans('marketplace.admin.payouts.mark_paid'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->visible(fn (DesignerPayout $p) => ! $p->isPaid())
                    ->modalHeading(fn (DesignerPayout $p) => trans('marketplace.admin.payouts.mark_heading', ['amount' => Currencies::format($p->amount_cents, $p->currency, $locale)]))
                    ->modalDescription(function (DesignerPayout $p) use ($designer) {
                        $details = $designer($p)?->payout_details ?? [];

                        return $details ? collect($details)->map(fn ($v, $k) => trans("marketplace.portal.payout.{$k}").': '.$v)->implode(' · ') : trans('marketplace.admin.designers.no_payout_details');
                    })
                    ->schema([
                        TextInput::make('reference')->label(trans('marketplace.admin.payouts.reference'))->helperText(trans('marketplace.admin.payouts.reference_help'))->required()->maxLength(120),
                        Textarea::make('note')->label(trans('marketplace.admin.payouts.note'))->rows(2)->maxLength(500),
                    ])
                    ->action(fn (DesignerPayout $record, array $data, Action $action) => MarketplaceActions::run(
                        $action,
                        fn () => app(DesignerLedger::class)->markPaid($record, auth()->user(), $data['reference'], $data['note'] ?? null),
                        trans('marketplace.admin.payouts.marked'),
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDesignerPayouts::route('/')];
    }
}
