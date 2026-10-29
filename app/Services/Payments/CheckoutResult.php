<?php

namespace App\Services\Payments;

/**
 * What PaymentGateway::start returns: where to send the customer (card
 * checkout), or the instructions to show them (bank transfer).
 */
final class CheckoutResult
{
    /**
     * @param  array<string, mixed>|null  $instructions
     */
    private function __construct(
        public readonly ?string $redirectUrl,
        public readonly ?array $instructions,
    ) {}

    public static function redirect(string $url): self
    {
        return new self($url, null);
    }

    /**
     * @param  array<string, mixed>  $instructions
     */
    public static function instructions(array $instructions): self
    {
        return new self(null, $instructions);
    }

    public function isRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }
}
