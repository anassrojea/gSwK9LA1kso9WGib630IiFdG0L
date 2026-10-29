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
            'items' => [['type' => 'plan', 'id' => 'premium', 'price_cents' => 2900]],
            'total_cents' => 2900,
            'currency' => 'USD',
            'method' => 'transfer',
            'status' => 'awaiting_payment',
            'expires_at' => now()->addHours(48),
        ];
    }
}
