<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\TransferReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransferReceipt>
 */
class TransferReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'received_via' => 'whatsapp',
            'received_at' => now(),
        ];
    }
}
