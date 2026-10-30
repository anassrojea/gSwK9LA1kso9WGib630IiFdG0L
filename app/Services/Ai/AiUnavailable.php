<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The AI service could not answer (network, rate limit, overload, bad key).
 * $reason goes to ai_generations.error and picks the message the host sees.
 */
class AiUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason = 'unavailable', string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message ?: $reason, 0, $previous);
    }
}
