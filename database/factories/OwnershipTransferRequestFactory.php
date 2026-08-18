<?php

namespace Database\Factories;

use App\Models\OwnershipTransferRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnershipTransferRequest>
 */
class OwnershipTransferRequestFactory extends Factory
{
    protected $model = OwnershipTransferRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_user_id' => User::factory(),
            'to_user_id' => User::factory(),
        ];
    }
}
