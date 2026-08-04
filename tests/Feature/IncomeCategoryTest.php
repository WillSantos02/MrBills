<?php

namespace Tests\Feature;

use App\Models\Income;
use App\Models\IncomeCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_totals_sums_all_incomes_regardless_of_date(): void
    {
        $user = User::factory()->create();
        $category = IncomeCategory::factory()->create(['user_id' => $user->id]);

        Income::factory()->create([
            'user_id' => $user->id,
            'income_category_id' => $category->id,
            'value' => 100,
            'date' => now()->startOfMonth()->addDays(2),
        ]);

        Income::factory()->create([
            'user_id' => $user->id,
            'income_category_id' => $category->id,
            'value' => 50,
            'date' => now()->subMonthsNoOverflow(2)->startOfMonth()->addDays(2),
        ]);

        $result = IncomeCategory::withTotals()->findOrFail($category->id);

        $this->assertEquals(150, $result->total_geral);
    }

    public function test_with_totals_month_sum_only_includes_incomes_dated_in_the_current_month(): void
    {
        $user = User::factory()->create();
        $category = IncomeCategory::factory()->create(['user_id' => $user->id]);

        Income::factory()->create([
            'user_id' => $user->id,
            'income_category_id' => $category->id,
            'value' => 100,
            'date' => now()->startOfMonth()->addDays(2),
        ]);

        Income::factory()->create([
            'user_id' => $user->id,
            'income_category_id' => $category->id,
            'value' => 999,
            'date' => now()->subMonthsNoOverflow(2)->startOfMonth()->addDays(2),
        ]);

        $result = IncomeCategory::withTotals()->findOrFail($category->id);

        $this->assertEquals(100, $result->total_mes_atual);
    }

    public function test_with_totals_is_null_for_a_category_with_no_incomes(): void
    {
        $category = IncomeCategory::factory()->create();

        $result = IncomeCategory::withTotals()->findOrFail($category->id);

        $this->assertNull($result->total_geral);
        $this->assertNull($result->total_mes_atual);
    }
}
