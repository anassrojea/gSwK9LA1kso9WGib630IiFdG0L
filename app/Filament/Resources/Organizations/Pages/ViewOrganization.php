<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Resources\Organizations\OrganizationActions;
use App\Filament\Resources\Organizations\OrganizationResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The organization page: profile, subscription and quota, members,
 * subscriptions, orders (with transfer approval) and events. The header
 * actions extend or cancel the live subscription, audit-logged.
 */
class ViewOrganization extends ViewRecord
{
    protected static string $resource = OrganizationResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            OrganizationActions::extend(),
            OrganizationActions::cancel(),
        ];
    }
}
