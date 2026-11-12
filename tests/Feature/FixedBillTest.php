<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\FixedBill;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FixedBillTest extends TestCase
{
    use RefreshDatabase;

    private function startFixed(User $user, string $dueDate = '2026-11-20'): FixedBill
    {
        return FixedBill::start([
            'user_id' => $user->id,
            'category_id' => null,
            'description' => 'Aluguel',
            'value' => '1500.00',
            'due_date' => $dueDate,
        ]);
    }

    public function test_day_31_is_anchored_to_start_date_instead_of_shrinking_after_february(): void
    {
        $this->travelTo(Carbon::parse('2027-01-05'));

        $fixed = $this->startFixed(User::factory()->create(), '2027-01-31');

        $this->assertSame('2027-02-28', $fixed->dueDateFor(2)->toDateString());
        $this->assertSame('2027-03-31', $fixed->dueDateFor(3)->toDateString());
    }

    public function test_daily_command_extends_the_window_as_time_passes(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12'));
        $fixed = $this->startFixed(User::factory()->create());
        $this->assertSame(12, Bill::count());

        $this->artisan('recurrences:generate-fixed')->assertSuccessful();
        $this->assertSame(12, Bill::count(), 'running twice in the same month must not duplicate');

        $this->travelTo(Carbon::parse('2026-12-01'));
        $this->artisan('recurrences:generate-fixed')->assertSuccessful();

        $this->assertSame(13, Bill::count());
        $this->assertSame(13, $fixed->fresh()->last_generated_occurrence);
        $this->assertTrue(Bill::whereDate('due_date', '2027-11-20')->exists());
    }

    public function test_deleting_a_single_occurrence_does_not_get_it_regenerated(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12'));
        $user = User::factory()->create();
        $this->startFixed($user);
        $this->actingAs($user);

        $last = Bill::orderByDesc('current_installments')->first();

        Livewire::test('list-bills')
            ->call('askDelete', $last->id)
            ->call('deleteOnlyThis');

        $this->artisan('recurrences:generate-fixed')->assertSuccessful();

        $this->assertSame(11, Bill::count());
        $this->assertNull(Bill::find($last->id));
    }

    public function test_deleting_this_and_future_ends_the_series(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12'));
        $user = User::factory()->create();
        $this->startFixed($user);
        $this->actingAs($user);

        $third = Bill::where('current_installments', 3)->sole();

        Livewire::test('list-bills')
            ->call('askDelete', $third->id)
            ->assertSet('deletingIsFixed', true)
            ->call('deleteThisAndFuture');

        $this->assertSame(2, Bill::count());
        $this->assertSame(0, FixedBill::count());

        $this->travelTo(Carbon::parse('2027-06-01'));
        $this->artisan('recurrences:generate-fixed')->assertSuccessful();
        $this->assertSame(2, Bill::count());
    }

    public function test_editing_with_apply_to_future_updates_pending_future_occurrences_and_template(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12'));
        $user = User::factory()->create();
        $fixed = $this->startFixed($user);
        $this->actingAs($user);

        Bill::where('current_installments', 4)->update(['status' => BillStatus::Pago->value]);
        $second = Bill::where('current_installments', 2)->sole();

        Livewire::test('list-bills')
            ->call('editBill', $second->id)
            ->assertSet('editingIsFixed', true)
            ->set('edit_value', '1650.00')
            ->set('edit_apply_to_future', true)
            ->call('updateBill')
            ->assertHasNoErrors();

        $values = Bill::orderBy('current_installments')->pluck('value', 'current_installments');
        $this->assertEquals(1500, $values[1]);   // anterior: intocada
        $this->assertEquals(1650, $values[2]);   // a editada
        $this->assertEquals(1650, $values[3]);   // futura pendente
        $this->assertEquals(1500, $values[4]);   // futura já paga: intocada
        $this->assertEquals(1650, $values[12]);
        $this->assertEquals(1650, $fixed->fresh()->value);

        $this->travelTo(Carbon::parse('2026-12-01'));
        $this->artisan('recurrences:generate-fixed');
        $this->assertEquals(1650, Bill::where('current_installments', 13)->sole()->value);
    }

    public function test_editing_without_apply_to_future_only_changes_that_occurrence(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12'));
        $user = User::factory()->create();
        $fixed = $this->startFixed($user);
        $this->actingAs($user);

        $first = Bill::where('current_installments', 1)->sole();

        Livewire::test('list-bills')
            ->call('editBill', $first->id)
            ->set('edit_value', '99.00')
            ->call('updateBill');

        $this->assertEquals(99, $first->fresh()->value);
        $this->assertEquals(1500, Bill::where('current_installments', 2)->sole()->value);
        $this->assertEquals(1500, $fixed->fresh()->value);
    }

    public function test_kind_filter_separates_spot_fixed_and_installment_bills(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12'));
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->startFixed($user);
        $spot = Bill::factory()->create(['user_id' => $user->id, 'is_spot' => true, 'status' => BillStatus::Pago, 'due_date' => '2026-11-12']);
        $single = Bill::factory()->create(['user_id' => $user->id, 'due_date' => '2026-11-17']);

        $component = Livewire::test('list-bills')->set('periodType', 'mes_atual');

        $this->assertEquals([$spot->id], $component->set('kindFilter', 'pontual')->viewData('bills')->pluck('id')->all());
        $this->assertEquals([$single->id], $component->set('kindFilter', 'unica')->viewData('bills')->pluck('id')->all());
        $this->assertCount(1, $component->set('kindFilter', 'fixa')->viewData('bills'));
        $this->assertCount(0, $component->set('kindFilter', 'parcelada')->viewData('bills'));
    }
}
