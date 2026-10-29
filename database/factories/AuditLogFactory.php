<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'action' => 'order.approved',
            'entity' => 'Order',
            'entity_id' => fake()->uuid(),
            'data' => [],
            'at' => now(),
        ];
    }
}
