<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // orders keep their customer (a restricting foreign key), and nobody deletes themselves
            DeleteAction::make()
                ->visible(fn (User $record) => ! $record->is(auth()->user()) && ! $record->orders()->exists())
                ->modalDescription(trans('admin.users.delete_warning'))
                ->before(fn (User $record) => AuditLog::record('user.deleted', $record, [
                    'email' => $record->email,
                    'phone' => $record->phone,
                    'events' => $record->events()->count(),
                ])),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $role = $data['role'];
        unset($data['role']);

        if ($record->role !== $role) {
            AuditLog::record('user.role_changed', $record, ['from' => $record->role, 'to' => $role]);
        }

        $record->fill($data)->forceFill(['role' => $role])->save();

        return $record;
    }
}
