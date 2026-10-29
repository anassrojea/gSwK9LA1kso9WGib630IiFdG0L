<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time email code (App\Services\Account\LoginCodes). code_hash is an
 * HMAC of the code with the app key, never the code itself.
 */
class LoginCode extends Model
{
    use HasUuids;

    public const LOGIN = 'login';

    public const DELETE = 'delete';

    /** confirms a new email address on the profile (accounts without a password) */
    public const EMAIL = 'email';

    protected $fillable = ['email', 'purpose', 'code_hash', 'attempts', 'expires_at', 'used_at', 'ip'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }
}
