<?php

namespace Database\Factories;

use App\Models\Income;
use App\Models\IncomeCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Income>
 */
class IncomeFactory extends Factory
{
    protected $model = Income::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'income_category_id' => IncomeCategory::factory(),
            'description' => fake()->sentence(3),
            'value' => fake()->randomFloat(2, 10, 1000),
            'date' => now()->toDateString(),
            'is_recurrent' => false,
            'total_installments' => 1,
            'current_installments' => 1,
        ];
    }
}
