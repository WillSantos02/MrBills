<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\CreditCard;
use App\Models\CreditCardPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CreditCardInstallmentsAndLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Quarta-feira, antes do fechamento (dia 10): ciclo aberto vence em 17/03/2027.
        $this->travelTo('2027-03-03 12:00:00');
    }

    public function test_split_puts_leftover_cents_on_the_first_installment(): void
    {
        $this->assertSame(['33.34', '33.33', '33.33'], CreditCardPurchase::splitInstallments('100', 3));
        $this->assertSame(['500.00', '500.00'], CreditCardPurchase::splitInstallments(1000, 2));
    }

    public function test_installments_land_in_consecutive_invoices(): void
    {
        $card = CreditCard::factory()->create(['closing_day' => 10, 'due_day' => 17]);

        $first = CreditCardPurchase::createInstallments([
            'user_id' => $card->user_id,
            'credit_card_id' => $card->id,
            'description' => 'TV',
            'value' => '1000',
            'purchase_date' => '2027-03-15',
        ], 3);

        $installments = $first->siblings()->with('bill')->orderBy('current_installments')->get();

        $this->assertCount(3, $installments);
        $this->assertSame(['TV - 1/3', 'TV - 2/3', 'TV - 3/3'], $installments->pluck('display_description')->all());
        $this->assertSame(
            ['2027-04-17', '2027-05-17', '2027-06-17'],
            $installments->map(fn ($i) => $i->bill->due_date->toDateString())->all(),
        );
        $this->assertEquals(1000, (float) Bill::where('credit_card_id', $card->id)->sum('value'));
    }

    public function test_installments_do_not_collapse_when_closing_day_is_clamped(): void
    {
        // Fecha dia 30, compra em 31/01 → ciclo de fevereiro (fecha 28/02). As parcelas seguintes não podem
        // cair na mesma fatura por causa do clamp de fim de mês.
        $card = CreditCard::factory()->create(['closing_day' => 30, 'due_day' => 8]);

        $first = CreditCardPurchase::createInstallments([
            'user_id' => $card->user_id,
            'credit_card_id' => $card->id,
            'description' => 'Curso',
            'value' => '300',
            'purchase_date' => '2027-01-31',
        ], 3);

        $this->assertSame(
            ['2027-03-08', '2027-04-08', '2027-05-08'],
            $first->siblings()->with('bill')->orderBy('current_installments')->get()
                ->map(fn ($i) => $i->bill->due_date->toDateString())->all(),
        );
    }

    public function test_installment_purchase_uses_full_value_of_the_limit_and_paying_invoices_releases_it(): void
    {
        $card = CreditCard::factory()->create(['closing_day' => 10, 'due_day' => 17, 'credit_limit' => 2000]);

        $first = CreditCardPurchase::createInstallments([
            'user_id' => $card->user_id,
            'credit_card_id' => $card->id,
            'description' => 'Celular',
            'value' => '1200',
            'purchase_date' => '2027-03-02',
        ], 4);

        $this->assertEquals(1200, $card->usedLimit());
        $this->assertEquals(800, $card->availableLimit());

        $first->bill->update(['status' => BillStatus::Pago]);

        $this->assertEquals(900, $card->usedLimit());
        $this->assertEquals(1100, $card->availableLimit());
    }

    public function test_card_without_limit_has_no_available_limit(): void
    {
        $card = CreditCard::factory()->create(['credit_limit' => null]);

        $this->assertNull($card->availableLimit());
    }

    public function test_purchase_over_the_available_limit_is_blocked(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $card = CreditCard::factory()->create(['user_id' => $user->id, 'credit_limit' => 1000]);
        CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 700, 'purchase_date' => '2027-03-01']);

        Livewire::test('create-card-purchase')
            ->set('credit_card_id', $card->id)
            ->set('description', 'Notebook')
            ->set('value', '300.01')
            ->set('purchase_date', '2027-03-02')
            ->set('is_installment', true)
            ->set('total_installments', 10)
            ->call('save')
            ->assertHasErrors('value');

        Livewire::test('create-card-purchase')
            ->set('credit_card_id', $card->id)
            ->set('description', 'Fone')
            ->set('value', '300')
            ->set('purchase_date', '2027-03-02')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(1000, $card->usedLimit());
    }

    public function test_creating_installments_through_the_form(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $card = CreditCard::factory()->create(['user_id' => $user->id, 'closing_day' => 10, 'due_day' => 17]);

        Livewire::test('create-card-purchase')
            ->set('credit_card_id', $card->id)
            ->set('description', 'Geladeira')
            ->set('value', '3000')
            ->set('purchase_date', '2027-03-02')
            ->set('is_installment', true)
            ->set('total_installments', 3)
            ->assertViewHas('lastInvoiceDue', fn ($due) => $due->toDateString() === '2027-05-17')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(3, CreditCardPurchase::count());
        $this->assertSame(3, Bill::where('credit_card_id', $card->id)->count());
        $this->assertEquals(1000, (float) Bill::where('credit_card_id', $card->id)->whereDate('due_date', '2027-03-17')->value('value'));
    }

    public function test_edit_that_exceeds_the_limit_is_blocked(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $card = CreditCard::factory()->create(['user_id' => $user->id, 'credit_limit' => 500]);
        $purchase = CreditCardPurchase::factory()->create(['credit_card_id' => $card->id, 'value' => 400, 'purchase_date' => '2027-03-01']);

        Livewire::test('list-card-purchases')
            ->call('editPurchase', $purchase->id)
            ->set('edit_value', '500.01')
            ->call('updatePurchase')
            ->assertHasErrors('edit_value');

        Livewire::test('list-card-purchases')
            ->call('editPurchase', $purchase->id)
            ->set('edit_value', '500')
            ->call('updatePurchase')
            ->assertHasNoErrors();
    }

    public function test_deleting_this_and_future_installments_keeps_earlier_and_paid_ones(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $card = CreditCard::factory()->create(['user_id' => $user->id, 'closing_day' => 10, 'due_day' => 17]);

        $first = CreditCardPurchase::createInstallments([
            'user_id' => $user->id,
            'credit_card_id' => $card->id,
            'description' => 'Sofá',
            'value' => '400',
            'purchase_date' => '2027-03-02',
        ], 4);
        $first->bill->update(['status' => BillStatus::Pago]);

        $second = $first->siblings()->where('current_installments', 2)->sole();

        Livewire::test('list-card-purchases')
            ->call('askDelete', $second->id)
            ->assertSet('deletingIsInstallment', true)
            ->call('deleteThisAndFuture');

        $this->assertSame([1], CreditCardPurchase::orderBy('current_installments')->pluck('current_installments')->all());
        $this->assertSame(1, Bill::where('credit_card_id', $card->id)->count());
    }

    public function test_deleting_only_one_installment(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $card = CreditCard::factory()->create(['user_id' => $user->id]);

        $first = CreditCardPurchase::createInstallments([
            'user_id' => $user->id,
            'credit_card_id' => $card->id,
            'description' => 'Mesa',
            'value' => '300',
            'purchase_date' => '2027-03-02',
        ], 3);

        $second = $first->siblings()->where('current_installments', 2)->sole();

        Livewire::test('list-card-purchases')
            ->call('askDelete', $second->id)
            ->call('deletePurchase');

        $this->assertSame([1, 3], CreditCardPurchase::orderBy('current_installments')->pluck('current_installments')->all());
    }
}
