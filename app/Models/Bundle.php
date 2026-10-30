<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A family journey bundle (phase 8D, plan section 14.2), e.g. "engagement +
 * henna + wedding": event-plan credits bought upfront at a discount.
 *
 * credits: [{plan: "premium", count: 2}, {plan: "signature", count: 1}]
 * prices:  {"USD": 10900, "SAR": 40900, "SYP": 1300000} in minor units of each currency
 */
class Bundle extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name_ar', 'name_en', 'description_ar', 'description_en', 'credits', 'prices', 'placeholder_prices',
        'validity_days', 'active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'array',
            'prices' => 'array',
            'placeholder_prices' => 'boolean',
            'validity_days' => 'integer',
            'active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function issued(): HasMany
    {
        return $this->hasMany(BundleCredit::class);
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }

    public function description(?string $locale = null): ?string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->description_ar : $this->description_en;
    }

    public function price(string $currency): ?int
    {
        $amount = $this->prices[$currency] ?? null;

        return $amount === null || $amount === '' ? null : (int) $amount;
    }

    /**
     * [plan id => count], counts above zero only.
     *
     * @return array<string, int>
     */
    public function creditCounts(): array
    {
        $counts = [];
        foreach ($this->credits ?? [] as $row) {
            $plan = (string) ($row['plan'] ?? '');
            $count = (int) ($row['count'] ?? 0);
            if ($plan !== '' && $count > 0) {
                $counts[$plan] = ($counts[$plan] ?? 0) + $count;
            }
        }

        return $counts;
    }
}
