<?php

namespace Tests\Feature\Livewire;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\CreditCard;
use App\Models\CreditCardPurchase;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CreditCardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Quarta-feira, antes do fechamento (dia 10): ciclo aberto vence em 17/03/2027 (quarta).
        $this->travelTo('2027-03-03 12:00:00');
    }

    public function test_user_can_register_a_card(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('create-credit-card')
            ->set('bank', 'Nubank')
            ->set('last_four_digits', '4321')
            ->set('color', 'laranja')
            ->set('credit_limit', '5000')
            ->set('closing_day', '10')
            ->set('due_day', '17')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('credit-card-created');

        $this->assertDatabaseHas('credit_cards', [
            'user_id' => $user->id,
            'bank' => 'Nubank',
            'last_four_digits' => '4321',
            'color' => 'laranja',
            'credit_limit' => 5000,
            'closing_day' => 10,
            'due_day' => 17,
        ]);
    }

    public function test_card_validation_rejects_bad_digits_and_days(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('create-credit-card')
            ->set('color', 'nao-existe')
            ->set('bank', 'Itaú')
            ->set('last_four_digits', '12a')
            ->set('closing_day', '32')
            ->set('due_day', '32')
            ->call('save')
            ->assertHasErrors(['color', 'last_four_digits', 'closing_day', 'due_day']);

        $this->assertSame(0, CreditCard::count());
    }

    public function test_adding_a_purchase_creates_the_invoice_that_shows_in_bills_and_dashboard_total(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $card = CreditCard::factory()->create(['user_id' => $user->id, 'closing_day' => 10, 'due_day' => 17]);

        Livewire::test('create-card-purchase')
            ->set('credit_card_id', $card->id)
            ->set('description', 'Mercado')
            ->set('value', '120.50')
            ->set('purchase_date', '2027-03-02')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('card-purchase-created');

        Livewire::test('create-card-purchase')
            ->set('credit_card_id', $card->id)
            ->set('description', 'Farmácia')
            ->set('value', '29.50')
            ->set('purchase_date', '2027-03-03')
            ->call('save');

        $invoice = Bill::where('credit_card_id', $card->id)->sole();
        $this->assertEquals(150, (float) $invoice->value);

        $billIds = Livewire::test('list-bills')->viewData('bills')->pluck('id');
        $this->assertContains($invoice->id, $billIds);

        $this->assertEquals(150, (float) Livewire::test('dashboard-summary')->viewData('totalAPagar'));

        $cartoes = Livewire::test('dashboard-summary')->viewData('cartoes');
        $this->assertCount(1, $cartoes);
        $this->assertEquals(150, $cartoes->first()['total']);
        $this->assertSame('2027-03-10', $cartoes->first()['closing']->toDateString());
        $this->assertSame('2027-03-17', $cartoes->first()['due']->toDateString());
    }

    public function test_purchase_into_an_already_paid_invoice_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $card = CreditCard::factory()->create(['user_id' => $user->id, 'closing_day' => 10, 'due_day' => 17]);

        $existing = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 10, 'purchase_date' => '2027-03-01']);
        $existing->bill->update(['status' => BillStatus::Pago]);

        Livewire::test('create-card-purchase')
            ->set('credit_card_id', $card->id)
            ->set('description', 'Atrasada')
            ->set('value', '5')
            ->set('purchase_date', '2027-03-02')
            ->call('save')
            ->assertHasErrors('purchase_date');

        $this->assertSame(1, CreditCardPurchase::count());
    }

    public function test_invoice_in_bills_list_only_allows_status_changes_and_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $card = CreditCard::factory()->create(['user_id' => $user->id, 'closing_day' => 10, 'due_day' => 17]);
        $purchase = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 99, 'purchase_date' => '2027-03-01']);
        $invoice = $purchase->bill;

        Livewire::test('list-bills')
            ->call('editBill', $invoice->id)
            ->set('edit_value', '1')
            ->set('edit_due_date', '2030-01-01')
            ->set('edit_status', BillStatus::Pago->value)
            ->call('updateBill')
            ->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame(BillStatus::Pago, $invoice->status);
        $this->assertEquals(99, (float) $invoice->value);
        $this->assertSame('2027-03-17', $invoice->due_date->toDateString());

        try {
            Livewire::test('list-bills')->call('askDelete', $invoice->id);
            $this->fail('Fatura não deveria poder ser excluída pela tela de Despesas.');
        } catch (ModelNotFoundException) {
            // esperado
        }

        $this->assertNotNull(Bill::find($invoice->id));
    }

    public function test_purchases_of_a_paid_invoice_cannot_be_edited_or_deleted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $card = CreditCard::factory()->create(['user_id' => $user->id]);
        $purchase = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'purchase_date' => '2027-03-01']);
        $purchase->bill->update(['status' => BillStatus::Pago]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test('list-card-purchases')->call('askDelete', $purchase->id);
    }

    public function test_editing_and_deleting_purchases_updates_the_invoice(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $card = CreditCard::factory()->create(['user_id' => $user->id, 'closing_day' => 10, 'due_day' => 17]);
        $a = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 30, 'purchase_date' => '2027-03-01']);
        $b = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 70, 'purchase_date' => '2027-03-02']);

        Livewire::test('list-card-purchases')
            ->call('editPurchase', $a->id)
            ->set('edit_value', '50')
            ->call('updatePurchase')
            ->assertHasNoErrors();

        $this->assertEquals(120, (float) $b->bill->fresh()->value);

        Livewire::test('list-card-purchases')
            ->call('askDelete', $b->id)
            ->call('deletePurchase');

        $this->assertEquals(50, (float) $a->bill->fresh()->value);
    }

    public function test_deleting_a_card_removes_pending_invoices_but_keeps_paid_ones_as_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $card = CreditCard::factory()->create(['user_id' => $user->id, 'closing_day' => 10, 'due_day' => 17]);

        $paidPurchase = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 10, 'purchase_date' => '2027-02-01']);
        $paidInvoice = $paidPurchase->bill;
        $paidInvoice->update(['status' => BillStatus::Pago]);
        $pendingInvoice = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'purchase_date' => '2027-03-01'])->bill;

        Livewire::test('list-credit-cards')
            ->call('askDelete', $card->id)
            ->assertSet('deletingPendingInvoices', 1)
            ->call('deleteCard');

        $this->assertNull(CreditCard::find($card->id));
        $this->assertSame(0, CreditCardPurchase::count());
        $this->assertNull(Bill::find($pendingInvoice->id));
        $this->assertNotNull($kept = Bill::find($paidInvoice->id));
        $this->assertNull($kept->credit_card_id);
        $this->assertEquals(10, (float) $kept->value);
    }

    public function test_family_members_share_cards_but_strangers_cannot_use_them(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);
        $stranger = User::factory()->create();
        $card = CreditCard::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($member);

        Livewire::test('create-card-purchase')
            ->set('credit_card_id', $card->id)
            ->set('description', 'Compra do membro')
            ->set('value', '15')
            ->set('purchase_date', '2027-03-01')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertCount(1, Livewire::test('list-credit-cards')->viewData('cards'));

        $this->actingAs($stranger);

        $this->assertCount(0, Livewire::test('list-credit-cards')->viewData('cards'));

        Livewire::test('create-card-purchase')
            ->set('credit_card_id', $card->id)
            ->set('description', 'Intruso')
            ->set('value', '15')
            ->set('purchase_date', '2027-03-01')
            ->call('save')
            ->assertHasErrors('credit_card_id');
    }

    public function test_cards_page_renders(): void
    {
        $user = User::factory()->create();
        CreditCard::factory()->create(['user_id' => $user->id, 'bank' => 'Banco Teste']);

        $this->actingAs($user)->get(route('cards.index'))->assertOk()->assertSee('Banco Teste');
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Cartões de Crédito');
    }
}
