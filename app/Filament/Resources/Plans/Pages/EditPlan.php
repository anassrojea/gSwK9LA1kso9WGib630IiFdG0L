<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Resources\Plans\PlanResource;
use App\Models\AuditLog;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    /**
     * Plan limits decide what every event on the plan gets (Entitlements): each change is audit-logged.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->fill($data);
        $changes = collect($record->getDirty())->keys()
            ->mapWithKeys(fn (string $key) => [$key => ['from' => $record->getOriginal($key), 'to' => $record->getAttribute($key)]])
            ->all();
        $record->save();

        if ($changes !== []) {
            AuditLog::record('plan.updated', $record, $changes);
        }

        return $record;
    }
}
