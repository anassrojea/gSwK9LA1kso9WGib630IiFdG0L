<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Marketplace\Pages\ListDesignerProfiles;
use App\Models\DesignerProfile;
use App\Services\Marketplace\Designers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Designers (phase 8C): applications to approve, suspensions and each designer's
 * commission. Payout details are decrypted only in the "Payout details" modal.
 */
class DesignerProfileResource extends Resource
{
    protected static ?string $model = DesignerProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Marketplace;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'marketplace/designers';

    public static function getModelLabel(): string
    {
        return trans('marketplace.admin.designers.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('marketplace.admin.designers.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = DesignerProfile::query()->where('status', 'pending')->count();

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
        $default = (int) config('marketplace.designer_share_bp', 7000);

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make(app()->getLocale() === 'ar' ? 'display_name_ar' : 'display_name_en')->label(trans('marketplace.admin.designers.name'))->searchable(['display_name_ar', 'display_name_en'])->description(fn (DesignerProfile $r) => $r->user?->email),
                TextColumn::make('status')->label(trans('marketplace.admin.status'))->badge()
                    ->formatStateUsing(fn (string $state) => trans("marketplace.statuses.designer.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('commission_bp')->label(trans('marketplace.admin.designers.share'))
                    ->state(fn (DesignerProfile $r) => rtrim(rtrim(number_format($r->shareBp() / 100, 2, '.', ''), '0'), '.').'%')
                    ->description(fn (DesignerProfile $r) => $r->commission_bp === null ? trans('marketplace.admin.designers.default_share') : null),
                TextColumn::make('templates_count')->label(trans('marketplace.admin.designers.templates'))->counts('templates'),
                TextColumn::make('country')->label(trans('marketplace.admin.designers.country'))->placeholder('—'),
                TextColumn::make('terms_version')->label(trans('marketplace.admin.designers.terms'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(trans('marketplace.admin.applied'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(trans('marketplace.admin.status'))->options(MarketplaceActions::options('marketplace.statuses.designer', DesignerProfile::STATUSES)),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(trans('marketplace.admin.approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (DesignerProfile $r) => $r->status !== 'approved')
                    ->requiresConfirmation()
                    ->action(fn (DesignerProfile $record, Action $action) => MarketplaceActions::run($action, fn () => app(Designers::class)->approve($record, auth()->user()), trans('marketplace.admin.done'))),
                ActionGroup::make([
                    Action::make('payout_details')
                        ->label(trans('marketplace.admin.designers.payout_details'))
                        ->icon(Heroicon::OutlinedBuildingLibrary)
                        ->modalSubmitAction(false)
                        ->schema(fn (DesignerProfile $record) => collect($record->payout_details ?? [])->map(fn ($value, $key) => TextEntry::make("payout_{$key}")->label(trans("marketplace.portal.payout.{$key}"))->state($value)->copyable())->values()->all()
                            ?: [TextEntry::make('none')->hiddenLabel()->state(trans('marketplace.admin.designers.no_payout_details'))]),
                    Action::make('commission')
                        ->label(trans('marketplace.admin.designers.set_share'))
                        ->icon(Heroicon::OutlinedReceiptPercent)
                        ->fillForm(fn (DesignerProfile $r) => ['percent' => $r->commission_bp !== null ? $r->commission_bp / 100 : null])
                        ->schema([
                            TextInput::make('percent')
                                ->label(trans('marketplace.admin.designers.share_percent'))
                                ->helperText(trans('marketplace.admin.designers.share_help', ['default' => $default / 100]))
                                ->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%'),
                        ])
                        ->action(fn (DesignerProfile $record, array $data, Action $action) => MarketplaceActions::run(
                            $action,
                            fn () => app(Designers::class)->setCommission($record, filled($data['percent'] ?? null) ? (int) round(((float) $data['percent']) * 100) : null, auth()->user()),
                            trans('marketplace.admin.done'),
                        )),
                    Action::make('suspend')
                        ->label(trans('marketplace.admin.designers.suspend'))
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->visible(fn (DesignerProfile $r) => $r->status !== 'suspended')
                        ->requiresConfirmation()
                        ->modalDescription(trans('marketplace.admin.designers.suspend_text'))
                        ->schema([Textarea::make('reason')->label(trans('marketplace.admin.reason'))->required()->rows(2)->maxLength(500)])
                        ->action(fn (DesignerProfile $record, array $data, Action $action) => MarketplaceActions::run($action, fn () => app(Designers::class)->suspend($record, auth()->user(), $data['reason']), trans('marketplace.admin.done'))),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDesignerProfiles::route('/')];
    }
}
