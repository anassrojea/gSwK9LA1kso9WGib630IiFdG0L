<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Filament\Resources\Catalog\CatalogActions;
use App\Filament\Resources\Events\EventActions;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Http\Controllers\Admin\PreviewController;
use App\Models\Event;
use App\Services\Dashboard\GuestBoard;
use App\Services\Digits;
use App\Services\Entitlements;
use App\Services\PublishGate;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The read-only event page: the host dashboard's numbers (GuestBoard), the
 * plan and features (Entitlements), whether it can be published (PublishGate)
 * and the invitation itself (the wizard preview, signed for the admin).
 * Everything here only reads.
 */
class EventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $n = fn (int $value) => Digits::localize($value, app()->getLocale());
        $stat = fn (string $key, string $label) => TextEntry::make("stats.{$key}")
            ->label($label)
            ->state(fn (Event $record) => $n((int) (static::stats($record)[$key] ?? 0)))
            ->size('lg')
            ->weight('bold');

        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Grid::make(1)
                ->columnSpan(['default' => 1, 'lg' => 2])
                ->schema([
                    Section::make(trans('admin.events.numbers'))
                        ->columns(['default' => 2, 'md' => 4])
                        ->schema([
                            $stat('invited', trans('admin.events.stats.invited')),
                            $stat('seats_allowed', trans('admin.events.stats.seats')),
                            $stat('opened', trans('admin.events.stats.opened')),
                            $stat('replied', trans('admin.events.stats.replied')),
                            $stat('attending', trans('admin.events.stats.attending')),
                            $stat('declined', trans('admin.events.stats.declined')),
                            $stat('never_opened', trans('admin.events.stats.never_opened')),
                            TextEntry::make('stats.guest_limit')->label(trans('admin.events.stats.limit'))
                                ->state(fn (Event $record) => ($limit = static::stats($record)['guest_limit']) === null ? '∞' : $n($limit))
                                ->size('lg')->weight('bold'),
                        ]),
                    Section::make(trans('admin.events.label'))
                        ->columns(['default' => 1, 'md' => 3])
                        ->schema([
                            TextEntry::make('occasion')->label(trans('admin.templates.occasion'))->formatStateUsing(fn (string $state) => trans("app.wizard.occasions.{$state}")),
                            TextEntry::make('status')->label(trans('app.admin.orders.status'))
                                ->formatStateUsing(fn (string $state) => trans("app.status.{$state}"))
                                ->badge()->color(fn (string $state) => EventsTable::STATUS_COLORS[$state] ?? 'gray'),
                            TextEntry::make('owner.name')->label(trans('app.admin.orders.customer'))->placeholder(trans('admin.events.guest_draft')),
                            TextEntry::make('starts_at')->label(trans('admin.events.starts_at'))->dateTime()->placeholder('—')
                                // the event's own local time, as hosts and guests see it
                                ->timezone(fn ($record) => $record->timezone ?: config('app.timezone')),
                            TextEntry::make('timezone')->label(trans('admin.events.timezone')),
                            TextEntry::make('venue_name')->label(trans('admin.events.venue'))->placeholder('—'),
                            TextEntry::make('invitation.published_at')->label(trans('admin.events.published'))->dateTime()->placeholder(trans('admin.events.not_published')),
                            TextEntry::make('invitation.template_id')->label(trans('admin.templates.label'))->placeholder('—'),
                            TextEntry::make('storage_until')->label(trans('admin.events.storage_until'))->date()->placeholder(trans('admin.events.storage_none')),
                        ]),
                    Section::make(trans('admin.events.plan_title'))
                        ->columns(['default' => 1, 'md' => 3])
                        ->schema([
                            TextEntry::make('current_plan')->label(trans('admin.events.plan'))
                                ->state(fn (Event $record) => trans('app.wizard.tiers.'.app(PublishGate::class)->currentPlan($record)))
                                ->badge()->color('info'),
                            TextEntry::make('gate')->label(trans('admin.events.publish_gate'))
                                ->state(fn (Event $record) => static::gate($record)['allowed'] ? trans('admin.events.gate_ok') : trans('admin.events.gate_needs', ['plan' => trans('app.wizard.tiers.'.static::gate($record)['required'])]))
                                ->badge()->color(fn (Event $record) => static::gate($record)['allowed'] ? 'success' : 'warning'),
                            TextEntry::make('gate_missing')->label(trans('admin.events.gate_missing'))
                                ->state(fn (Event $record) => collect(static::gate($record)['missing'])->map(fn ($r) => trans("app.wizard.review.reasons.{$r}"))->all() ?: ['—']),
                            TextEntry::make('features')->label(trans('admin.events.features'))
                                ->state(fn (Event $record) => collect(app(Entitlements::class)->features($record))->map(fn ($f) => EventActions::featureLabel($f))->all())
                                ->badge()->color('gray')
                                ->columnSpanFull(),
                        ]),
                ]),
            Section::make(trans('admin.events.invitation'))
                ->columnSpan(1)
                ->schema([
                    Html::make(fn (Event $record) => $record->invitation
                        ? CatalogActions::frame(PreviewController::eventUrl($record))
                        : '<p class="text-sm text-gray-500">'.e(trans('admin.events.no_invitation')).'</p>'),
                ]),
        ]);
    }

    public static function stats(Event $event): array
    {
        return app(GuestBoard::class)->stats($event);
    }

    public static function gate(Event $event): array
    {
        return app(PublishGate::class)->check($event);
    }
}
