<?php

namespace Tests\Feature;

use App\Models\Income;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_description_for_non_recurrent_income_is_the_raw_description(): void
    {
        $income = Income::factory()->create([
            'description' => 'Salário',
            'is_recurrent' => false,
        ]);

        $this->assertSame('Salário', $income->display_description);
    }

    public function test_display_description_for_recurrent_income_appends_installment_suffix(): void
    {
        $income = Income::factory()->create([
            'description' => 'Freelance',
            'is_recurrent' => true,
            'total_installments' => 6,
            'current_installments' => 2,
        ]);

        $this->assertSame('Freelance - 2/6', $income->display_description);
    }

    public function test_create_recurrent_generates_one_income_per_installment_sharing_a_group_id(): void
    {
        $user = User::factory()->create();

        $firstIncome = Income::createRecurrent([
            'user_id' => $user->id,
            'description' => 'Salário',
            'value' => 3000,
            'date' => '2026-01-05',
            'total_installments' => 3,
        ]);

        $this->assertDatabaseCount('incomes', 3);

        $installments = Income::where('recurrence_group_id', $firstIncome->recurrence_group_id)
            ->orderBy('current_installments')
            ->get();

        $this->assertCount(3, $installments);
        $this->assertTrue($installments->every(fn (Income $income) => $income->is_recurrent));
        $this->assertTrue($installments->every(fn (Income $income) => $income->total_installments === 3));
        $this->assertSame([1, 2, 3], $installments->pluck('current_installments')->all());
        $this->assertSame(
            ['2026-01-05', '2026-02-05', '2026-03-05'],
            $installments->map(fn (Income $income) => $income->date->toDateString())->all()
        );
        $this->assertSame($firstIncome->id, $installments->first()->id);
    }

    public function test_create_recurrent_does_not_overflow_the_month_for_a_31st_date(): void
    {
        $firstIncome = Income::createRecurrent([
            'user_id' => User::factory()->create()->id,
            'description' => 'Aluguel recebido',
            'value' => 1200,
            'date' => '2026-01-31',
            'total_installments' => 2,
        ]);

        $secondInstallment = Income::where('recurrence_group_id', $firstIncome->recurrence_group_id)
            ->where('current_installments', 2)
            ->firstOrFail();

        $this->assertSame('2026-02-28', $secondInstallment->date->toDateString());
    }

    public function test_siblings_returns_all_installments_of_the_same_recurrence_group(): void
    {
        $firstIncome = Income::createRecurrent([
            'user_id' => User::factory()->create()->id,
            'description' => 'Salário',
            'value' => 3000,
            'date' => '2026-01-05',
            'total_installments' => 3,
        ]);

        $this->assertCount(3, $firstIncome->siblings);
    }

    public function test_siblings_excludes_incomes_from_a_different_recurrence_group(): void
    {
        $user = User::factory()->create();

        $firstIncome = Income::createRecurrent([
            'user_id' => $user->id,
            'description' => 'Grupo A',
            'value' => 100,
            'date' => '2026-01-05',
            'total_installments' => 2,
        ]);

        Income::createRecurrent([
            'user_id' => $user->id,
            'description' => 'Grupo B',
            'value' => 200,
            'date' => '2026-01-10',
            'total_installments' => 2,
        ]);

        $this->assertCount(2, $firstIncome->siblings);
    }
}
