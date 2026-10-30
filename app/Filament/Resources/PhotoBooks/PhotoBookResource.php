<?php

namespace App\Filament\Resources\PhotoBooks;

use App\Filament\AdminNavigation;
use App\Filament\Resources\PhotoBooks\Pages\ManagePhotoBooks;
use App\Models\PhotoBook;
use App\Services\Keepsakes\PhotoBookPdf;
use App\Services\Keepsakes\PhotoBooks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnitEnum;

/**
 * Printed photo books (phase 8D, plan section 14.5). No print partner yet:
 * the admin downloads the print-ready PDF, prints, ships, and moves each
 * paid book along pending → printing → shipped → delivered (or cancels it),
 * with tracking notes the host sees on their keepsakes page.
 */
class PhotoBookResource extends Resource
{
    protected static ?string $model = PhotoBook::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Sales;

    protected static ?int $navigationSort = 6;

    public static function getModelLabel(): string
    {
        return trans('keepsakes.admin.books.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('keepsakes.admin.books.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = PhotoBook::query()->whereIn('status', ['pending', 'printing'])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['event:id,title', 'user:id,name,email', 'order:id,code']))
            ->defaultSort('paid_at', 'desc')
            ->columns([
                TextColumn::make('status')->label(trans('keepsakes.admin.books.status'))->badge()
                    ->formatStateUsing(fn (string $state) => trans("keepsakes.photo_book.status.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'warning',
                        'printing' => 'info',
                        'shipped' => 'primary',
                        'delivered' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('event.title')->label(trans('keepsakes.admin.books.event'))->searchable()->limit(30),
                TextColumn::make('user.name')->label(trans('keepsakes.admin.books.customer'))->description(fn (PhotoBook $r) => $r->user?->email),
                TextColumn::make('order.code')->label(trans('keepsakes.admin.books.order')),
                TextColumn::make('pages')->label(trans('keepsakes.admin.books.pages')),
                TextColumn::make('size')->label(trans('keepsakes.admin.books.size'))->formatStateUsing(fn (string $state) => trans("keepsakes.photo_book.sizes.{$state}")),
                TextColumn::make('copies')->label(trans('keepsakes.admin.books.copies')),
                TextColumn::make('address')->label(trans('keepsakes.admin.books.address'))
                    ->state(fn (PhotoBook $r) => collect([$r->ship_name, $r->ship_address, $r->ship_city, $r->ship_country, $r->ship_phone])->filter()->implode(' · '))
                    ->wrap(),
                TextColumn::make('tracking_notes')->label(trans('keepsakes.admin.books.tracking'))->wrap()->toggleable(),
                TextColumn::make('paid_at')->label(trans('keepsakes.admin.books.paid_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(trans('keepsakes.admin.books.status'))
                    ->options(collect(PhotoBook::STATUSES)->mapWithKeys(fn (string $s) => [$s => trans("keepsakes.photo_book.status.{$s}")])->all()),
            ])
            ->recordActions([
                ActionGroup::make([
                    ...array_map(fn (string $status) => static::move($status), ['printing', 'shipped', 'delivered', 'cancelled']),
                    Action::make('make_pdf')
                        ->label(trans('keepsakes.admin.books.make_pdf'))
                        ->icon(Heroicon::OutlinedDocumentArrowDown)
                        ->visible(fn (PhotoBook $r) => $r->status !== 'cancelled')
                        ->action(function (PhotoBook $record) {
                            try {
                                app(PhotoBookPdf::class)->generate($record);
                                Notification::make()->title(trans('keepsakes.photo_book.pdf_ready', ['pages' => $record->fresh()->pdf_pages]))->success()->send();
                            } catch (Throwable $e) {
                                report($e);
                                Notification::make()->title(trans('keepsakes.photo_book.errors.render_failed'))->danger()->send();
                            }
                        }),
                    Action::make('pdf')
                        ->label(trans('keepsakes.admin.books.pdf'))
                        ->icon(Heroicon::OutlinedDocument)
                        ->visible(fn (PhotoBook $r) => (bool) $r->pdf_key)
                        ->url(fn (PhotoBook $r) => route('photo-books.pdf', $r), shouldOpenInNewTab: true),
                ]),
            ]);
    }

    /**
     * "Move to printing / shipped / delivered / cancelled", with an optional tracking note.
     */
    public static function move(string $status): Action
    {
        return Action::make("move_{$status}")
            ->label(trans('keepsakes.admin.books.move', ['status' => trans("keepsakes.photo_book.status.{$status}")]))
            ->icon(match ($status) {
                'printing' => Heroicon::OutlinedPrinter,
                'shipped' => Heroicon::OutlinedTruck,
                'delivered' => Heroicon::OutlinedCheckBadge,
                default => Heroicon::OutlinedXCircle,
            })
            ->color($status === 'cancelled' ? 'danger' : 'primary')
            ->visible(fn (PhotoBook $record) => $record->canMoveTo($status))
            ->schema([
                Textarea::make('note')->label(trans('keepsakes.admin.books.note'))->rows(2)->maxLength(500),
            ])
            ->action(function (PhotoBook $record, array $data) use ($status) {
                try {
                    app(PhotoBooks::class)->transition($record, $status, auth()->user(), $data['note'] ?? null);
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                }
            });
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePhotoBooks::route('/'),
        ];
    }
}
