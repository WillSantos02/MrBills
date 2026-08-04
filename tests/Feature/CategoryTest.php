<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_totals_sums_all_bills_regardless_of_due_date(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id]);

        Bill::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'value' => 100,
            'due_date' => now()->startOfMonth()->addDays(2),
        ]);

        Bill::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'value' => 50,
            'due_date' => now()->subMonthsNoOverflow(2)->startOfMonth()->addDays(2),
        ]);

        $result = Category::withTotals()->findOrFail($category->id);

        $this->assertEquals(150, $result->total_geral);
    }

    public function test_with_totals_month_sum_only_includes_bills_due_in_the_current_month(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id]);

        Bill::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'value' => 100,
            'due_date' => now()->startOfMonth()->addDays(2),
        ]);

        Bill::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'value' => 999,
            'due_date' => now()->subMonthsNoOverflow(2)->startOfMonth()->addDays(2),
        ]);

        $result = Category::withTotals()->findOrFail($category->id);

        $this->assertEquals(100, $result->total_mes_atual);
    }

    public function test_with_totals_is_null_for_a_category_with_no_bills(): void
    {
        $category = Category::factory()->create();

        $result = Category::withTotals()->findOrFail($category->id);

        $this->assertNull($result->total_geral);
        $this->assertNull($result->total_mes_atual);
    }
}
