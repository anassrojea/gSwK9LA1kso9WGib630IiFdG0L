<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventActions;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Event;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * "Open as the customer (read-only)": what the host sees for this event,
 * without signing in as them and without anything that writes. The only
 * actions are the admin's own, each confirmed and audit-logged.
 */
class ViewEvent extends ViewRecord
{
    protected static string $resource = EventResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->title;
    }

    public function getSubheading(): ?string
    {
        return trans('admin.events.read_only');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('customer')
                ->label(trans('app.admin.orders.customer'))
                ->icon(Heroicon::OutlinedUser)
                ->color('gray')
                ->visible(fn (Event $record) => $record->owner_id !== null)
                ->url(fn (Event $record) => UserResource::getUrl('view', ['record' => $record->owner_id])),
            EventActions::preview(),
            EventActions::extendStorage(),
            EventActions::grant(),
        ];
    }
}
