<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A gateway that reports payments back: POST /webhooks/{gateway} hands it the
 * raw request. It must verify the signature itself and be idempotent (providers retry).
 */
interface HandlesWebhooks
{
    public function handleWebhook(Request $request): Response;
}
