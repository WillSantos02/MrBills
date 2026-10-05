<?php

namespace Database\Factories;

use App\Enums\CardColor;
use App\Models\CreditCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditCard>
 */
class CreditCardFactory extends Factory
{
    protected $model = CreditCard::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bank' => fake()->randomElement(['Nubank', 'Itaú', 'Inter', 'Bradesco', 'C6']),
            'last_four_digits' => fake()->numerify('####'),
            'color' => fake()->randomElement(CardColor::cases()),
            'credit_limit' => null,
            'closing_day' => 10,
            'due_day' => 17,
        ];
    }
}
