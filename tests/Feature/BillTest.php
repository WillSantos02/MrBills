<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillTest extends TestCase
{
    use RefreshDatabase;

    public function test_saturday_due_date_rolls_forward_to_the_following_monday(): void
    {
        $saturday = Carbon::parse('2026-08-08');
        $this->assertTrue($saturday->isSaturday());

        $bill = Bill::factory()->create(['due_date' => $saturday]);

        $this->assertTrue($bill->actual_due_date->isMonday());
        $this->assertSame('2026-08-10', $bill->actual_due_date->toDateString());
    }

    public function test_sunday_due_date_rolls_forward_to_the_following_monday(): void
    {
        $sunday = Carbon::parse('2026-08-09');
        $this->assertTrue($sunday->isSunday());

        $bill = Bill::factory()->create(['due_date' => $sunday]);

        $this->assertTrue($bill->actual_due_date->isMonday());
        $this->assertSame('2026-08-10', $bill->actual_due_date->toDateString());
    }

    public function test_weekday_due_date_is_left_unchanged(): void
    {
        $monday = Carbon::parse('2026-08-10');
        $this->assertTrue($monday->isMonday());

        $bill = Bill::factory()->create(['due_date' => $monday]);

        $this->assertSame('2026-08-10', $bill->actual_due_date->toDateString());
    }

    public function test_pending_bill_past_due_is_effectively_vencido(): void
    {
        $bill = Bill::factory()->create([
            'status' => BillStatus::Pendente,
            'due_date' => now()->subDays(5),
        ]);

        $this->assertSame(BillStatus::Vencido, $bill->effective_status);
    }

    public function test_pending_bill_due_in_the_future_stays_pendente(): void
    {
        $bill = Bill::factory()->create([
            'status' => BillStatus::Pendente,
            'due_date' => now()->addDays(5),
        ]);

        $this->assertSame(BillStatus::Pendente, $bill->effective_status);
    }

    public function test_paid_bill_past_due_does_not_become_vencido(): void
    {
        $bill = Bill::factory()->create([
            'status' => BillStatus::Pago,
            'due_date' => now()->subDays(5),
        ]);

        $this->assertSame(BillStatus::Pago, $bill->effective_status);
    }

    public function test_renegociado_bill_past_due_does_not_become_vencido(): void
    {
        $bill = Bill::factory()->create([
            'status' => BillStatus::Renegociado,
            'due_date' => now()->subDays(5),
        ]);

        $this->assertSame(BillStatus::Renegociado, $bill->effective_status);
    }

    public function test_display_description_for_non_recurrent_bill_is_the_raw_description(): void
    {
        $bill = Bill::factory()->create([
            'description' => 'Conta de luz',
            'is_recurrent' => false,
        ]);

        $this->assertSame('Conta de luz', $bill->display_description);
    }

    public function test_display_description_for_recurrent_bill_appends_installment_suffix(): void
    {
        $bill = Bill::factory()->create([
            'description' => 'Financiamento',
            'is_recurrent' => true,
            'total_installments' => 12,
            'current_installments' => 3,
        ]);

        $this->assertSame('Financiamento - 3/12', $bill->display_description);
    }

    public function test_create_recurrent_generates_one_bill_per_installment_sharing_a_group_id(): void
    {
        $user = User::factory()->create();

        $firstBill = Bill::createRecurrent([
            'user_id' => $user->id,
            'description' => 'Parcelamento',
            'value' => 100,
            'due_date' => '2026-01-15',
            'total_installments' => 3,
        ]);

        $this->assertDatabaseCount('bills', 3);

        $installments = Bill::where('recurrence_group_id', $firstBill->recurrence_group_id)
            ->orderBy('current_installments')
            ->get();

        $this->assertCount(3, $installments);
        $this->assertTrue($installments->every(fn (Bill $bill) => $bill->is_recurrent));
        $this->assertTrue($installments->every(fn (Bill $bill) => $bill->total_installments === 3));
        $this->assertSame([1, 2, 3], $installments->pluck('current_installments')->all());
        $this->assertSame(
            ['2026-01-15', '2026-02-15', '2026-03-15'],
            $installments->map(fn (Bill $bill) => $bill->due_date->toDateString())->all()
        );
        $this->assertSame($firstBill->id, $installments->first()->id);
    }

    public function test_create_recurrent_does_not_overflow_the_month_for_a_31st_due_date(): void
    {
        $firstBill = Bill::createRecurrent([
            'user_id' => User::factory()->create()->id,
            'description' => 'Assinatura',
            'value' => 50,
            'due_date' => '2026-01-31',
            'total_installments' => 2,
        ]);

        $secondInstallment = Bill::where('recurrence_group_id', $firstBill->recurrence_group_id)
            ->where('current_installments', 2)
            ->firstOrFail();

        // addMonthsNoOverflow: 31/Jan + 1 mês não deve virar 03/Mar, deve ficar em 28/Fev (2026 não é bissexto).
        $this->assertSame('2026-02-28', $secondInstallment->due_date->toDateString());
    }

    public function test_siblings_returns_all_installments_of_the_same_recurrence_group(): void
    {
        $firstBill = Bill::createRecurrent([
            'user_id' => User::factory()->create()->id,
            'description' => 'Parcelamento',
            'value' => 100,
            'due_date' => '2026-01-15',
            'total_installments' => 3,
        ]);

        $this->assertCount(3, $firstBill->siblings);
    }

    public function test_siblings_excludes_bills_from_a_different_recurrence_group(): void
    {
        $user = User::factory()->create();

        $firstBill = Bill::createRecurrent([
            'user_id' => $user->id,
            'description' => 'Grupo A',
            'value' => 100,
            'due_date' => '2026-01-15',
            'total_installments' => 2,
        ]);

        Bill::createRecurrent([
            'user_id' => $user->id,
            'description' => 'Grupo B',
            'value' => 200,
            'due_date' => '2026-01-20',
            'total_installments' => 2,
        ]);

        $this->assertCount(2, $firstBill->siblings);
    }
}
