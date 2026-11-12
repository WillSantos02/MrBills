<?php

namespace Tests\Feature\Livewire;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\FixedBill;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CreateBillTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_spot_expense_is_stored_as_paid_on_its_own_date_without_weekend_rollover(): void
    {
        $this->actingAs(User::factory()->create());

        $saturday = Carbon::parse('2026-11-14');
        $this->assertTrue($saturday->isSaturday());

        Livewire::test('create-bill')
            ->set('status', BillStatus::Pago->value)
            ->set('description', 'Padaria')
            ->set('value', '18.50')
            ->set('due_date', $saturday->toDateString())
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('bill-created');

        $bill = Bill::sole();
        $this->assertSame(BillStatus::Pago, $bill->status);
        $this->assertTrue($bill->is_spot);
        $this->assertFalse($bill->is_recurrent);
        $this->assertSame('2026-11-14', $bill->actual_due_date->toDateString());
        $this->assertSame('Pontual', $bill->kindLabel());
    }

    public function test_choosing_paid_prefills_todays_date(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('create-bill')
            ->set('status', BillStatus::Pago->value)
            ->assertSet('due_date', today()->toDateString());
    }

    public function test_pending_single_bill_keeps_the_weekend_rollover(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('create-bill')
            ->set('description', 'Boleto')
            ->set('value', '100')
            ->set('due_date', '2026-11-14')
            ->call('save')
            ->assertHasNoErrors();

        $bill = Bill::sole();
        $this->assertSame(BillStatus::Pendente, $bill->status);
        $this->assertFalse($bill->is_spot);
        $this->assertSame('2026-11-16', $bill->actual_due_date->toDateString());
    }

    public function test_status_other_than_pending_or_paid_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('create-bill')
            ->set('status', BillStatus::Vencido->value)
            ->set('description', 'X')
            ->set('value', '10')
            ->set('due_date', today()->toDateString())
            ->call('save')
            ->assertHasErrors('status');

        $this->assertSame(0, Bill::count());
    }

    public function test_installment_bill_marks_only_the_first_installment_as_paid(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('create-bill')
            ->set('kind', 'parcelada')
            ->set('total_installments', 3)
            ->set('status', BillStatus::Pago->value)
            ->set('description', 'Geladeira')
            ->set('value', '500')
            ->set('due_date', '2026-11-10')
            ->call('save')
            ->assertHasNoErrors();

        $bills = Bill::orderBy('current_installments')->get();
        $this->assertCount(3, $bills);
        $this->assertSame(BillStatus::Pago, $bills[0]->status);
        $this->assertSame(BillStatus::Pendente, $bills[1]->status);
        $this->assertSame(BillStatus::Pendente, $bills[2]->status);
        $this->assertSame('Geladeira - 1/3', $bills[0]->display_description);
    }

    public function test_fixed_bill_creates_template_and_rolling_window_of_occurrences(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 10:00'));
        $this->actingAs(User::factory()->create());

        Livewire::test('create-bill')
            ->set('kind', 'fixa')
            ->set('description', 'Energia')
            ->set('value', '230.40')
            ->set('due_date', '2026-11-20')
            ->call('save')
            ->assertHasNoErrors();

        $fixed = FixedBill::sole();
        $bills = Bill::orderBy('current_installments')->get();

        // Nov/2026 até Out/2027 = 12 meses.
        $this->assertCount(FixedBill::HORIZON_MONTHS, $bills);
        $this->assertSame(12, $fixed->last_generated_occurrence);
        $this->assertSame('2026-11-20', $bills->first()->due_date->toDateString());
        $this->assertSame('2027-10-20', $bills->last()->due_date->toDateString());
        $this->assertTrue($bills->every(fn (Bill $b) => $b->is_fixed && $b->recurrence_group_id === $fixed->recurrence_group_id));
        $this->assertSame('Energia', $bills->first()->display_description);
        $this->assertSame('Fixa', $bills->first()->kindLabel());
    }
}
