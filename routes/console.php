<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

// Horizon's metrics graphs need a snapshot every five minutes (Forge runs schedule:run every minute).
Schedule::command('horizon:snapshot')->everyFiveMinutes();

Artisan::command('daawa:make-admin {email} {--name=}', function (string $email) {
    $user = User::query()->firstOrNew(['email' => $email]);
    $password = null;

    if (! $user->exists) {
        $password = Str::password(20);
        $user->fill(['name' => $this->option('name') ?: Str::before($email, '@'), 'password' => $password]);
    }

    $user->forceFill(['role' => User::ROLE_ADMIN, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
    AuditLog::record('user.role_changed', $user, ['to' => User::ROLE_ADMIN, 'via' => 'artisan']);

    $this->info("{$email} is now an admin (/admin).");
    if ($password) {
        $this->warn("Temporary password: {$password}");
    }
})->purpose('Create an admin user, or promote an existing one');
