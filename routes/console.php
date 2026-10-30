<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

// Horizon's metrics graphs need a snapshot every five minutes (Forge runs schedule:run every minute).
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Transfer orders without a receipt expire 48 hours after checkout (plan section 12.2)
Schedule::command('orders:expire')->everyFifteenMinutes()->withoutOverlapping();

// Account deletions run once their grace period is over (plan section 20, config/account.php)
Schedule::command('account:purge-deleted')->dailyAt('03:17')->withoutOverlapping();

// Storage period (plan section 11, config/retention.php): warnings 14 and 3 days ahead, media purge, archive
Schedule::command('events:expire-storage')->dailyAt('04:23')->withoutOverlapping();

// Venue & Planner Pro (phase 8A): transfer renewal orders 7 days ahead, reminders, past_due → expired after the grace period
Schedule::command('org:renewal-orders')->hourlyAt(7)->withoutOverlapping();
Schedule::command('org:renewal-reminders')->dailyAt('09:41')->withoutOverlapping();
Schedule::command('org:expire-subscriptions')->hourlyAt(37)->withoutOverlapping();

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
