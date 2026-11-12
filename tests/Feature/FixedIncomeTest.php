<?php

namespace Tests\Feature;

use App\Models\FixedIncome;
use App\Models\Income;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FixedIncomeTest extends TestCase
{
    use RefreshDatabase;

    private function createSalary(): void
    {
        Livewire::test('create-income')
            ->set('kind', 'fixa')
            ->set('description', 'Salário')
            ->set('value', '5000')
            ->set('date', '2026-11-05')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('income-created');
    }

    public function test_fixed_income_creates_template_and_rolling_window(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12'));
        $this->actingAs(User::factory()->create());

        $this->createSalary();

        $fixed = FixedIncome::sole();
        $incomes = Income::orderBy('current_installments')->get();

        $this->assertCount(FixedIncome::HORIZON_MONTHS, $incomes);
        $this->assertSame('2027-10-05', $incomes->last()->date->toDateString());
        $this->assertTrue($incomes->every(fn (Income $i) => $i->is_fixed && $i->recurrence_group_id === $fixed->recurrence_group_id));
        $this->assertSame('Salário', $incomes->first()->display_description);
        $this->assertSame('Fixa', $incomes->first()->kindLabel());
    }

    public function test_daily_command_extends_fixed_incomes_without_duplicating(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12'));
        $this->actingAs(User::factory()->create());
        $this->createSalary();

        $this->artisan('recurrences:generate-fixed')->assertSuccessful();
        $this->assertSame(12, Income::count());

        $this->travelTo(Carbon::parse('2026-12-01'));
        $this->artisan('recurrences:generate-fixed')->assertSuccessful();
        $this->assertSame(13, Income::count());
    }

    public function test_deleting_this_and_future_ends_the_series(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12'));
        $this->actingAs(User::factory()->create());
        $this->createSalary();

        $third = Income::where('current_installments', 3)->sole();

        Livewire::test('list-income')
            ->call('askDelete', $third->id)
            ->assertSet('deletingIsFixed', true)
            ->call('deleteThisAndFuture');

        $this->assertSame(2, Income::count());
        $this->assertSame(0, FixedIncome::count());

        $this->travelTo(Carbon::parse('2027-06-01'));
        $this->artisan('recurrences:generate-fixed');
        $this->assertSame(2, Income::count());
    }

    public function test_raise_applied_to_future_updates_next_occurrences_and_template(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12'));
        $this->actingAs(User::factory()->create());
        $this->createSalary();

        $second = Income::where('current_installments', 2)->sole();

        Livewire::test('list-income')
            ->call('editIncome', $second->id)
            ->assertSet('editingIsFixed', true)
            ->set('edit_value', '5500')
            ->set('edit_apply_to_future', true)
            ->call('updateIncome')
            ->assertHasNoErrors();

        $values = Income::orderBy('current_installments')->pluck('value', 'current_installments');
        $this->assertEquals(5000, $values[1]);
        $this->assertEquals(5500, $values[2]);
        $this->assertEquals(5500, $values[12]);
        $this->assertEquals(5500, FixedIncome::sole()->value);
    }

    public function test_kind_filter_on_income_list(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12'));
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->createSalary();
        $single = Income::factory()->create(['user_id' => $user->id, 'date' => '2026-11-20']);

        $component = Livewire::test('list-income');

        $this->assertEquals([$single->id], $component->set('kindFilter', 'unica')->viewData('incomes')->pluck('id')->all());
        $this->assertCount(1, $component->set('kindFilter', 'fixa')->viewData('incomes'));
        $this->assertCount(0, $component->set('kindFilter', 'recorrente')->viewData('incomes'));
    }

    public function test_installment_recurrent_income_still_works(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('create-income')
            ->set('kind', 'recorrente')
            ->set('total_installments', 3)
            ->set('description', 'Freela')
            ->set('value', '800')
            ->set('date', '2026-11-05')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(3, Income::count());
        $this->assertSame('Freela - 1/3', Income::orderBy('current_installments')->first()->display_description);
        $this->assertSame(0, FixedIncome::count());
    }
}
