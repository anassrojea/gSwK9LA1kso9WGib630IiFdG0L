<?php

namespace App\Filament\Resources\Marketplace;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Marketplace\Pages\ListTemplateRevisions;
use App\Models\Template;
use App\Models\TemplateRevision;
use App\Services\Marketplace\MarketplacePrices;
use App\Services\Marketplace\TemplateReview;
use App\Services\Marketplace\TemplateStudio;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The marketplace review queue (phase 8C): revisions designers submitted, with a preview
 * in the real guest view, approve (it goes live on the template) or reject with a reason.
 * Opens on "In review"; the filter shows the history.
 */
class TemplateRevisionResource extends Resource
{
    protected static ?string $model = TemplateRevision::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Marketplace;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'marketplace/review';

    public static function getModelLabel(): string
    {
        return trans('marketplace.admin.review.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('marketplace.admin.review.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = TemplateRevision::query()->where('status', 'in_review')->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
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
        return parent::getEloquentQuery()->with(['template.designerProfile', 'opening']);
    }

    public static function table(Table $table): Table
    {
        $locale = app()->getLocale();

        return $table
            ->defaultSort('submitted_at', 'asc')
            ->columns([
                ColorColumn::make('swatch')
                    ->label('')
                    ->state(fn (TemplateRevision $r) => array_values(array_slice(array_intersect_key($r->default_config['theme']['colors'] ?? [], array_flip(['bg', 'accent', 'gold'])), 0, 3))),
                TextColumn::make($locale === 'ar' ? 'name_ar' : 'name_en')->label(trans('marketplace.admin.review.template'))->searchable(['name_ar', 'name_en'])
                    ->description(fn (TemplateRevision $r) => trans('marketplace.admin.review.revision', ['number' => $r->number]).' · '.$r->template_id),
                TextColumn::make('template.designerProfile.display_name_en')->label(trans('marketplace.admin.review.designer'))
                    ->state(fn (TemplateRevision $r) => $r->template?->designerProfile?->displayName($locale)),
                TextColumn::make('occasion')->label(trans('marketplace.admin.review.occasion'))->formatStateUsing(fn (string $state) => trans("app.wizard.occasions.{$state}")),
                TextColumn::make('opening_id')->label(trans('marketplace.admin.review.opening'))->state(fn (TemplateRevision $r) => $r->opening ? ($locale === 'ar' ? $r->opening->name_ar : $r->opening->name_en) : $r->opening_id)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('prices')->label(trans('marketplace.admin.review.prices'))->state(fn (TemplateRevision $r) => implode(' · ', MarketplacePrices::formatted($r->prices, $locale)))->wrap(),
                IconColumn::make('included_in_signature')->label(trans('marketplace.admin.review.signature'))->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label(trans('marketplace.admin.status'))->badge()
                    ->formatStateUsing(fn (string $state) => trans("marketplace.statuses.revision.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'in_review' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('submitted_at')->label(trans('marketplace.admin.review.submitted'))->since()->sortable()->placeholder('—'),
                TextColumn::make('rejection_reason')->label(trans('marketplace.admin.reason'))->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(trans('marketplace.admin.status'))
                    ->options(MarketplaceActions::options('marketplace.statuses.revision', TemplateRevision::STATUSES))
                    ->default('in_review'),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label(trans('marketplace.admin.review.preview'))
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (TemplateRevision $r) => route('designer.revisions.preview', $r), shouldOpenInNewTab: true),
                Action::make('approve')
                    ->label(trans('marketplace.admin.approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (TemplateRevision $r) => $r->status === 'in_review')
                    ->requiresConfirmation()
                    ->modalDescription(trans('marketplace.admin.review.approve_text'))
                    ->action(fn (TemplateRevision $record, Action $action) => MarketplaceActions::run($action, fn () => app(TemplateReview::class)->approve($record, auth()->user()), trans('marketplace.admin.review.approved'))),
                ActionGroup::make([
                    Action::make('reject')
                        ->label(trans('marketplace.admin.reject'))
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('danger')
                        ->visible(fn (TemplateRevision $r) => $r->status === 'in_review')
                        ->schema([
                            Textarea::make('reason')->label(trans('marketplace.admin.reason'))->helperText(trans('marketplace.admin.review.reason_help'))->required()->rows(3)->maxLength(1000),
                        ])
                        ->action(fn (TemplateRevision $record, array $data, Action $action) => MarketplaceActions::run($action, fn () => app(TemplateReview::class)->reject($record, auth()->user(), $data['reason']), trans('marketplace.admin.review.rejected'))),
                    Action::make('unpublish')
                        ->label(trans('marketplace.admin.review.unpublish'))
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->color('gray')
                        ->visible(fn (TemplateRevision $r) => $r->status === 'approved' && $r->template?->marketplace_status === 'published')
                        ->requiresConfirmation()
                        ->action(fn (TemplateRevision $record, Action $action) => MarketplaceActions::run($action, fn () => app(TemplateStudio::class)->unpublish(Template::query()->findOrFail($record->template_id)), trans('marketplace.admin.done'))),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTemplateRevisions::route('/')];
    }
}
