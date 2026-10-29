<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'provider' => 'stripe',
            'provider_ref' => 'pi_'.fake()->unique()->bothify('????????????'),
            'amount_cents' => 2900,
            'status' => 'succeeded',
        ];
    }
}
