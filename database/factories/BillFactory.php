<?php

namespace Database\Factories;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Category;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bill>
 */
class BillFactory extends Factory
{
    protected $model = Bill::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'description' => fake()->sentence(3),
            'value' => fake()->randomFloat(2, 10, 1000),
            // Terça-feira futura: dia útil, não colide com o rollover de fim de semana
            // testado explicitamente em BillTest.
            'due_date' => now()->next(Carbon::TUESDAY)->toDateString(),
            'is_recurrent' => false,
            'total_installments' => 1,
            'current_installments' => 1,
            'status' => BillStatus::Pendente,
        ];
    }
}
