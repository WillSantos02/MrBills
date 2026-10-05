<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\CreditCard;
use App\Models\CreditCardPurchase;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CarryOverOverdueInvoicesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cartão fecha dia 10 e vence dia 17. Compra em 02/03/2027 → fatura vence quarta 17/03/2027.
     *
     * @return array{0: CreditCard, 1: Bill}
     */
    private function cardWithInvoiceDueMarch17(float $value = 250, ?float $limit = null): array
    {
        $this->travelTo('2027-03-02 12:00:00');

        $card = CreditCard::factory()->create(['closing_day' => 10, 'due_day' => 17, 'credit_limit' => $limit]);
        $purchase = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => $value, 'purchase_date' => '2027-03-02']);

        return [$card, $purchase->bill];
    }

    public function test_nothing_happens_on_the_due_date_itself(): void
    {
        [$card, $invoice] = $this->cardWithInvoiceDueMarch17();
        $this->travelTo('2027-03-17 10:00:00');

        $this->artisan('credit-cards:carry-over-overdue-invoices')->assertSuccessful();

        $this->assertSame(BillStatus::Pendente, $invoice->fresh()->status);
        $this->assertSame(0.0, $card->overdueBalance());
    }

    public function test_overdue_invoice_is_renegotiated_and_its_balance_added_to_the_next_invoice(): void
    {
        [$card, $invoice] = $this->cardWithInvoiceDueMarch17(250);
        $this->travelTo('2027-03-18 00:10:00');

        // Antes do job rodar o aviso já aparece (fatura vencida ainda não transportada).
        $this->assertEquals(250, $card->overdueBalance());

        $this->artisan('credit-cards:carry-over-overdue-invoices')->assertSuccessful();

        $this->assertSame(BillStatus::Renegociado, $invoice->fresh()->status);

        $carry = CreditCardPurchase::whereNotNull('carried_from_bill_id')->sole();
        $this->assertSame($invoice->id, $carry->carried_from_bill_id);
        $this->assertEquals(250, (float) $carry->value);
        $this->assertSame('2027-04-17', $carry->bill->due_date->toDateString());
        $this->assertEquals(250, (float) $carry->bill->value);

        // Sem contagem dupla: só a fatura nova (pendente) entra no Total a Pagar / limite.
        $this->assertEquals(250, (float) Bill::where('status', BillStatus::Pendente->value)->sum('value'));
        $this->assertEquals(250, $card->usedLimit());
        $this->assertEquals(250, $card->overdueBalance());

        // Rodar de novo não transporta duas vezes.
        $this->artisan('credit-cards:carry-over-overdue-invoices')->assertSuccessful();
        $this->assertSame(1, CreditCardPurchase::whereNotNull('carried_from_bill_id')->count());
    }

    public function test_carried_balance_sums_with_new_purchases_and_paying_clears_the_warning(): void
    {
        [$card] = $this->cardWithInvoiceDueMarch17(250, 1000);
        $this->travelTo('2027-03-18 09:00:00');
        $this->artisan('credit-cards:carry-over-overdue-invoices');

        $new = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 100, 'purchase_date' => '2027-03-18']);

        $this->assertEquals(350, (float) $new->fresh()->bill->value);
        $this->assertEquals(650, $card->availableLimit());

        $new->fresh()->bill->update(['status' => BillStatus::Pago]);

        $this->assertSame(0.0, $card->overdueBalance());
        $this->assertEquals(1000, $card->availableLimit());
    }

    public function test_marking_the_renegotiated_invoice_as_paid_removes_the_carried_balance(): void
    {
        [$card, $invoice] = $this->cardWithInvoiceDueMarch17(250);
        $this->travelTo('2027-03-18 09:00:00');
        $this->artisan('credit-cards:carry-over-overdue-invoices');

        $invoice->fresh()->update(['status' => BillStatus::Pago]);

        $this->assertSame(0, CreditCardPurchase::whereNotNull('carried_from_bill_id')->count());
        $this->assertSame(0.0, $card->overdueBalance());
        $this->assertSame(0, Bill::where('credit_card_id', $card->id)->where('status', BillStatus::Pendente->value)->count());
    }

    public function test_consecutive_overdue_invoices_chain_into_the_next_one(): void
    {
        [$card, $march] = $this->cardWithInvoiceDueMarch17(100);
        $april = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 50, 'purchase_date' => '2027-03-20'])->bill;

        // Job parado por semanas: março e abril vencem sem pagamento.
        $this->travelTo('2027-04-20 09:00:00');
        $this->artisan('credit-cards:carry-over-overdue-invoices');

        $this->assertSame(BillStatus::Renegociado, $march->fresh()->status);
        $this->assertSame(BillStatus::Renegociado, $april->fresh()->status);

        $may = Bill::where('credit_card_id', $card->id)->where('status', BillStatus::Pendente->value)->sole();
        $this->assertSame('2027-05-17', $may->due_date->toDateString());
        $this->assertEquals(150, (float) $may->value);
        $this->assertEquals(150, $card->overdueBalance());
    }

    public function test_dashboard_shows_the_pending_invoice_warning(): void
    {
        [$card] = $this->cardWithInvoiceDueMarch17(250);
        $this->actingAs($card->user);
        $this->travelTo('2027-03-18 09:00:00');
        $this->artisan('credit-cards:carry-over-overdue-invoices');

        $cartoes = Livewire::test('dashboard-summary')->viewData('cartoes');
        $this->assertEquals(250, $cartoes->first()['overdue']);

        $this->get(route('dashboard'))->assertOk()->assertSee('Fatura pendente');
    }

    public function test_carried_balance_is_not_editable_by_the_user(): void
    {
        [$card] = $this->cardWithInvoiceDueMarch17(250);
        $this->actingAs(User::find($card->user_id));
        $this->travelTo('2027-03-18 09:00:00');
        $this->artisan('credit-cards:carry-over-overdue-invoices');

        $carry = CreditCardPurchase::whereNotNull('carried_from_bill_id')->sole();

        $this->expectException(ModelNotFoundException::class);
        Livewire::test('list-card-purchases')->call('askDelete', $carry->id);
    }
}
