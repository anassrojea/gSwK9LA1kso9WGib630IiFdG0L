<?php

namespace App\Support;

use Illuminate\Support\Facades\Vite;
use Throwable;

/**
 * Built asset URLs for the guest invitation head (plan section 21): the
 * opening's own chunk is preloaded so it downloads with invite.js instead of
 * after it (one round trip less before the opening can play).
 */
class InviteAssets
{
    public static function openingChunk(string $openingId): ?string
    {
        if (! preg_match('/^[a-z0-9-]{2,40}$/', $openingId) || Vite::isRunningHot()) {
            return null;
        }

        try {
            return Vite::asset("resources/js/openings/{$openingId}.js");
        } catch (Throwable) {
            return null;
        }
    }
}
