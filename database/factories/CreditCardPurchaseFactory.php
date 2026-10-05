<?php

namespace Database\Factories;

use App\Models\CreditCard;
use App\Models\CreditCardPurchase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditCardPurchase>
 */
class CreditCardPurchaseFactory extends Factory
{
    protected $model = CreditCardPurchase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'credit_card_id' => CreditCard::factory(),
            // Mesmo dono do cartão por padrão; bill_id é preenchido pelo hook saving do model.
            'user_id' => fn (array $attributes) => CreditCard::whereKey($attributes['credit_card_id'])->value('user_id'),
            'description' => fake()->sentence(3),
            'value' => fake()->randomFloat(2, 10, 500),
            'purchase_date' => now()->toDateString(),
        ];
    }
}
