<?php

namespace Database\Seeders;

use App\Models\Bundle;
use Illuminate\Database\Seeder;

/**
 * Family journey bundles (phase 8D, plan section 14.2). PLACEHOLDER prices
 * (bundles.placeholder_prices = true) until the owner saves real ones in
 * Admin → Bundles; re-seeding never overwrites a bundle an admin saved.
 *
 * Separately the plans would cost: engagement + henna + wedding = Premium +
 * Premium + Signature = $137; wedding + baby welcome = Signature + Premium = $108.
 */
class JourneySeeder extends Seeder
{
    public const BUNDLES = [
        'engagement-henna-wedding' => [
            'name_ar' => 'خطوبة + حنّاء + زفاف',
            'name_en' => 'Engagement + henna + wedding',
            'description_ar' => 'ثلاث مناسبات في رحلة واحدة: خطوبة وحنّاء بالباقة المميّزة، والزفاف بالباقة الفاخرة.',
            'description_en' => 'Three occasions, one journey: engagement and henna on Premium, the wedding on Signature.',
            'credits' => [['plan' => 'premium', 'count' => 2], ['plan' => 'signature', 'count' => 1]],
            'prices' => ['USD' => 10900, 'SAR' => 40900, 'SYP' => 1300000],
            'sort' => 10,
        ],
        'wedding-baby' => [
            'name_ar' => 'زفاف + استقبال مولود',
            'name_en' => 'Wedding + baby welcome',
            'description_ar' => 'الزفاف بالباقة الفاخرة، واستقبال المولود بعده بالباقة المميّزة.',
            'description_en' => 'The wedding on Signature, then the baby welcome on Premium.',
            'credits' => [['plan' => 'signature', 'count' => 1], ['plan' => 'premium', 'count' => 1]],
            'prices' => ['USD' => 8900, 'SAR' => 33500, 'SYP' => 1070000],
            'sort' => 20,
        ],
    ];

    public function run(): void
    {
        foreach (self::BUNDLES as $id => $bundle) {
            $existing = Bundle::query()->find($id);
            // an admin's saved bundle wins over the seed
            if ($existing && ! $existing->placeholder_prices) {
                continue;
            }

            Bundle::query()->updateOrCreate(['id' => $id], $bundle + [
                'validity_days' => (int) config('journey.bundles.validity_days', 730),
                'placeholder_prices' => true,
                'active' => true,
            ]);
        }
    }
}
