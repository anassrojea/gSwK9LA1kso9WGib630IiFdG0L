<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'items' => [['type' => 'plan', 'id' => 'premium', 'name' => 'Premium', 'quantity' => 1, 'unit_amount' => 2900, 'amount' => 2900]],
            'total_cents' => 2900,
            'currency' => 'USD',
            'method' => 'transfer',
            'gateway' => 'transfer',
            'status' => 'awaiting_payment',
            'expires_at' => now()->addHours(48),
        ];
    }

    public function card(string $gateway = 'stripe'): static
    {
        return $this->state(['method' => 'card', 'gateway' => $gateway, 'expires_at' => null]);
    }
}
