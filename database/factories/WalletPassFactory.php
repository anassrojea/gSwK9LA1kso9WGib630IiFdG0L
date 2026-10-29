<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\WalletPass;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WalletPass>
 */
class WalletPassFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'platform' => 'apple',
            'serial' => (string) Str::uuid(),
        ];
    }
}
