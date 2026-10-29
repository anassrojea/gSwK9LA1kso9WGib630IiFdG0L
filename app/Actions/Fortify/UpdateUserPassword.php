<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/**
 * Account → Security: change the password, or set a first one for an account
 * that signs in with Google or an email code (no current password to give).
 */
class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => $user->hasPassword() ? ['required', 'string', 'current_password:web'] : ['nullable'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => trans('app.account.errors.wrong_password'),
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
    }
}
