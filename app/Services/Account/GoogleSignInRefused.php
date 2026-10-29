<?php

namespace App\Services\Account;

use RuntimeException;

/**
 * Google sign-in could not go ahead. The reason is a key under app.auth.google.errors.
 */
class GoogleSignInRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Google sign-in refused: {$reason}");
    }
}
