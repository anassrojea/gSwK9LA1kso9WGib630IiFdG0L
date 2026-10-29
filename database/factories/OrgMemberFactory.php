<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrgMember>
 */
class OrgMemberFactory extends Factory
{
    protected $model = OrgMember::class;

    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => OrgMember::ROLE_STAFF,
        ];
    }
}
