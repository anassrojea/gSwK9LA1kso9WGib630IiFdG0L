<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\VenueProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VenueProfile>
 */
class VenueProfileFactory extends Factory
{
    protected $model = VenueProfile::class;

    public function definition(): array
    {
        return [
            'org_id' => Organization::factory()->venue(),
            'slug' => 'venue-'.fake()->unique()->numerify('#####'),
            'venue_type' => 'hall',
            'city' => 'riyadh',
            'area' => 'Al Malqa',
            'description_ar' => 'قاعة أفراح فاخرة في شمال الرياض.',
            'description_en' => 'An elegant wedding hall in north Riyadh.',
            'capacity' => 400,
            'amenities' => ['parking', 'prayer_room', 'women_section'],
            'whatsapp' => '966500000001',
            'listed' => false,
            'status' => VenueProfile::STATUS_DRAFT,
        ];
    }

    /** Listed by the venue and approved by an admin. */
    public function approved(): static
    {
        return $this->state(['listed' => true, 'status' => VenueProfile::STATUS_APPROVED, 'approved_at' => now()]);
    }
}
