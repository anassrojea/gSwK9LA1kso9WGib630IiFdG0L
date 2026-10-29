<?php

namespace App\Filament\Resources\DiscountCodes\Pages;

use App\Filament\Resources\DiscountCodes\DiscountCodeResource;
use App\Models\AuditLog;
use App\Models\DiscountCode;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDiscountCodes extends ManageRecords
{
    protected static string $resource = DiscountCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data) {
                    $code = new DiscountCode(DiscountCodeResource::normalizeData($data));
                    $code->created_by = auth()->id();
                    $code->save();
                    AuditLog::record('discount.created', $code, ['code' => $code->code]);

                    return $code;
                }),
        ];
    }
}
