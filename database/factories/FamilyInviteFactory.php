<?php

namespace Database\Factories;

use App\Models\FamilyInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FamilyInvite>
 */
class FamilyInviteFactory extends Factory
{
    protected $model = FamilyInvite::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'invited_user_id' => User::factory(),
        ];
    }
}
