<?php

namespace App\Actions\Fortify;

use App\Http\Middleware\SetLocale;
use App\Models\LoginCode;
use App\Models\User;
use App\Services\Account\LoginCodes;
use App\Services\Dashboard\PhoneNumber;
use App\Services\Payments\Currencies;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * Account → Profile (and Fortify's PUT /user/profile-information).
 *
 * A new email address needs proof: the current password, or for accounts
 * without one (Google, email code) a code sent to the new address. phone,
 * locale, country and currency are optional so Fortify's own route keeps
 * working with just name and email. country and currency are the checkout
 * defaults (App\Services\Payments\CheckoutService::options).
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        $input['email'] = LoginCodes::normalize((string) ($input['email'] ?? ''));
        if (array_key_exists('phone', $input)) {
            $input['phone'] = PhoneNumber::normalize(is_string($input['phone']) ? $input['phone'] : null);
            if ($input['phone'] && str_starts_with($input['phone'], '00')) {
                $input['phone'] = '+'.substr($input['phone'], 2);
            }
        }
        if (($input['country'] ?? null) === 'other' || ($input['country'] ?? null) === '') {
            $input['country'] = null;
        }
        if (($input['currency'] ?? null) === '') {
            $input['currency'] = null;
        }

        $emailChanged = $input['email'] !== LoginCodes::normalize((string) $user->email);

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            // international form: + then 7 to 15 digits
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/', Rule::unique('users')->ignore($user->id)],
            'locale' => ['sometimes', 'required', Rule::in(SetLocale::supported())],
            'country' => ['sometimes', 'nullable', Rule::in(array_keys(config('payments.countries', [])))],
            'currency' => ['sometimes', 'nullable', Rule::in(Currencies::all())],
            'current_password' => $emailChanged && $user->hasPassword() ? ['required', 'string', 'current_password:web'] : ['nullable'],
            'email_code' => $emailChanged && ! $user->hasPassword() ? ['required', 'string'] : ['nullable'],
        ], [
            'phone.regex' => trans('app.account.profile.errors.phone'),
            'current_password.current_password' => trans('app.account.errors.wrong_password'),
            'current_password.required' => trans('app.account.profile.errors.password_for_email'),
            'email_code.required' => trans('app.account.profile.errors.code_for_email'),
        ])->validate();

        if ($emailChanged && ! $user->hasPassword()) {
            try {
                app(LoginCodes::class)->verify($input['email'], LoginCode::EMAIL, (string) $input['email_code']);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['email_code' => $e->errors()['code'] ?? [trans('app.auth.code.wrong')]]);
            }
        }

        $attributes = ['name' => $input['name'], 'email' => $input['email']];
        foreach (['phone', 'locale', 'country', 'currency'] as $field) {
            if (array_key_exists($field, $input)) {
                $attributes[$field] = $input[$field];
            }
        }

        if ($emailChanged) {
            // a code proves the new inbox; a password alone does not
            $attributes['email_verified_at'] = $user->hasPassword() ? null : now();
        }

        $user->forceFill($attributes)->save();

        if ($emailChanged && $user->hasPassword() && $user instanceof MustVerifyEmail) {
            $user->sendEmailVerificationNotification();
        }
    }
}
