<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Controllers\Auth\EmailCodeController;
use App\Services\Account\LoginCodes;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // "Continue with Google" shows only when GOOGLE_CLIENT_ID is set; "Email me a code" when login codes are on
        Fortify::loginView(fn () => Inertia::render('auth/Login', [
            'status' => session('status'),
            'canResetPassword' => true,
            'loginCodes' => LoginCodes::enabled(),
            'codeEmail' => session(EmailCodeController::SESSION_KEY),
        ]));
        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'loginCodes' => LoginCodes::enabled(),
        ]));
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('auth/ForgotPassword', [
            'status' => session('status'),
        ]));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->string('email')->toString(),
            'token' => $request->route('token'),
        ]));

        // "Verify your email": shown when an unverified account opens /email/verify (or follows a prompt)
        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'email' => $request->user()?->email,
            'status' => session('status'),
        ]));

        // the link itself (GET /email/verify/{id}/{hash}) a few times a minute; a new link 3 times per 10 minutes
        RateLimiter::for('verification', function (Request $request) {
            $key = 'verify:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

            return $request->routeIs('verification.send')
                ? Limit::perMinutes(10, 3)->by($key.':send')
                : Limit::perMinute(6)->by($key);
        });

        // in the account's language (the notification runs in User::preferredLocale)
        VerifyEmail::toMailUsing(fn (object $notifiable, string $url) => (new MailMessage)
            ->subject(trans('app.verify.mail.subject'))
            ->greeting(trans('app.verify.mail.greeting', ['name' => $notifiable->name ?? '']))
            ->line(trans('app.verify.mail.line'))
            ->action(trans('app.verify.mail.action'), $url)
            ->line(trans('app.verify.mail.ignore'))
            ->salutation(trans('app.verify.mail.salutation')));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
