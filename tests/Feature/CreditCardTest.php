<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\CreditCard;
use App\Models\CreditCardPurchase;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_on_or_before_closing_day_goes_to_this_months_invoice(): void
    {
        $card = CreditCard::factory()->make(['closing_day' => 10, 'due_day' => 17]);

        $this->assertSame('2026-10-17', $card->invoiceDueDateFor(CarbonImmutable::parse('2026-10-08'))->toDateString());
        $this->assertSame('2026-10-17', $card->invoiceDueDateFor(CarbonImmutable::parse('2026-10-10'))->toDateString());
    }

    public function test_purchase_after_closing_day_goes_to_next_months_invoice(): void
    {
        $card = CreditCard::factory()->make(['closing_day' => 10, 'due_day' => 17]);

        $this->assertSame('2026-11-17', $card->invoiceDueDateFor(CarbonImmutable::parse('2026-10-15'))->toDateString());
        $this->assertSame('2027-01-17', $card->invoiceDueDateFor(CarbonImmutable::parse('2026-12-20'))->toDateString());
    }

    public function test_due_day_before_closing_day_falls_in_the_month_after_closing(): void
    {
        $card = CreditCard::factory()->make(['closing_day' => 25, 'due_day' => 5]);

        $this->assertSame('2026-11-05', $card->invoiceDueDateFor(CarbonImmutable::parse('2026-10-20'))->toDateString());
        $this->assertSame('2026-12-05', $card->invoiceDueDateFor(CarbonImmutable::parse('2026-10-26'))->toDateString());
        $this->assertSame('2026-10-25', $card->closingDateForInvoiceDue(CarbonImmutable::parse('2026-11-05'))->toDateString());
    }

    public function test_days_beyond_month_length_are_clamped_to_the_last_day(): void
    {
        $card = CreditCard::factory()->make(['closing_day' => 31, 'due_day' => 8]);

        // Fevereiro/2027 tem 28 dias: fecha dia 28, então compra em 28/02 ainda é do ciclo de fevereiro.
        $this->assertSame('2027-02-28', $card->closingDateFor(CarbonImmutable::parse('2027-02-28'))->toDateString());
        $this->assertSame('2027-03-08', $card->invoiceDueDateFor(CarbonImmutable::parse('2027-02-28'))->toDateString());
        $this->assertSame('2027-04-08', $card->invoiceDueDateFor(CarbonImmutable::parse('2027-03-01'))->toDateString());
    }

    public function test_purchases_create_a_single_invoice_bill_whose_value_is_their_sum(): void
    {
        $card = CreditCard::factory()->create(['closing_day' => 10, 'due_day' => 17, 'bank' => 'Nubank', 'last_four_digits' => '1234']);

        $first = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 100, 'purchase_date' => '2026-10-02']);
        CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 50.25, 'purchase_date' => '2026-10-09']);

        $invoices = Bill::where('credit_card_id', $card->id)->get();

        $this->assertCount(1, $invoices);
        $invoice = $invoices->first();
        $this->assertSame($first->bill_id, $invoice->id);
        $this->assertEquals(150.25, (float) $invoice->value);
        $this->assertSame('2026-10-17', $invoice->due_date->toDateString());
        $this->assertSame($card->user_id, $invoice->user_id);
        $this->assertSame(BillStatus::Pendente, $invoice->status);
        $this->assertSame('Fatura Nubank •••• 1234', $invoice->description);
    }

    public function test_purchase_after_closing_creates_the_next_invoice(): void
    {
        $card = CreditCard::factory()->create(['closing_day' => 10, 'due_day' => 17]);

        CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 100, 'purchase_date' => '2026-10-08']);
        CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 40, 'purchase_date' => '2026-10-15']);

        $this->assertEqualsCanonicalizing(
            ['2026-10-17' => 100.0, '2026-11-17' => 40.0],
            Bill::where('credit_card_id', $card->id)->get()
                ->mapWithKeys(fn (Bill $bill) => [$bill->due_date->toDateString() => (float) $bill->value])
                ->all(),
        );
    }

    public function test_changing_purchase_date_moves_it_between_invoices_and_empty_invoices_disappear(): void
    {
        $card = CreditCard::factory()->create(['closing_day' => 10, 'due_day' => 17]);

        $purchase = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 80, 'purchase_date' => '2026-10-05']);
        $oldInvoiceId = $purchase->bill_id;

        $purchase->update(['purchase_date' => '2026-10-20']);

        $this->assertNull(Bill::find($oldInvoiceId));
        $this->assertSame('2026-11-17', $purchase->fresh()->bill->due_date->toDateString());
        $this->assertEquals(80, (float) $purchase->fresh()->bill->value);
    }

    public function test_deleting_purchases_recalculates_and_finally_removes_the_invoice(): void
    {
        $card = CreditCard::factory()->create(['closing_day' => 10, 'due_day' => 17]);

        $a = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 30, 'purchase_date' => '2026-10-01']);
        $b = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 70, 'purchase_date' => '2026-10-02']);

        $a->delete();
        $this->assertEquals(70, (float) Bill::findOrFail($b->bill_id)->value);

        $b->delete();
        $this->assertSame(0, Bill::where('credit_card_id', $card->id)->count());
    }

    public function test_resync_moves_pending_purchases_to_the_new_cycle_but_keeps_paid_invoices(): void
    {
        $card = CreditCard::factory()->create(['closing_day' => 10, 'due_day' => 17]);

        $paid = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 10, 'purchase_date' => '2026-09-05']);
        $paid->bill->update(['status' => BillStatus::Pago]);
        $pending = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 20, 'purchase_date' => '2026-10-12']);

        $card->update(['closing_day' => 15, 'due_day' => 22, 'bank' => 'Inter']);
        $card->resyncPendingInvoices();

        $this->assertSame('2026-09-17', $paid->fresh()->bill->due_date->toDateString());
        $this->assertSame('2026-10-22', $pending->fresh()->bill->due_date->toDateString());
        $this->assertSame(2, Bill::where('credit_card_id', $card->id)->count());
        $this->assertStringContainsString('Inter', $pending->fresh()->bill->description);
    }
}
