<?php

namespace Tests\Feature\Livewire;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ListBillsFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtering_by_vencido_reconstructs_the_derived_status_condition(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $pendingFuture = Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Pendente,
            'due_date' => now()->addDays(5),
        ]);

        $paid = Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Pago,
            'due_date' => now()->subDays(5),
        ]);

        $renegociado = Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Renegociado,
            'due_date' => now()->subDays(5),
        ]);

        $overdue = Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Pendente,
            'due_date' => now()->subDays(5),
        ]);

        $component = Livewire::test('list-bills')
            ->set('periodType', 'geral')
            ->set('statusFilter', [BillStatus::Vencido->value]);

        $ids = $component->viewData('bills')->pluck('id');

        $this->assertEqualsCanonicalizing([$overdue->id], $ids->all());
        $this->assertNotContains($pendingFuture->id, $ids);
        $this->assertNotContains($paid->id, $ids);
        $this->assertNotContains($renegociado->id, $ids);
    }

    public function test_filtering_by_pendente_excludes_bills_that_are_effectively_overdue(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $pendingFuture = Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Pendente,
            'due_date' => now()->addDays(5),
        ]);

        $overdue = Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Pendente,
            'due_date' => now()->subDays(5),
        ]);

        $component = Livewire::test('list-bills')
            ->set('periodType', 'geral')
            ->set('statusFilter', [BillStatus::Pendente->value]);

        $ids = $component->viewData('bills')->pluck('id');

        $this->assertEqualsCanonicalizing([$pendingFuture->id], $ids->all());
        $this->assertNotContains($overdue->id, $ids);
    }

    public function test_status_filter_accepts_multiple_values_as_a_union(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $pendingFuture = Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Pendente,
            'due_date' => now()->addDays(5),
        ]);

        $overdue = Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Pendente,
            'due_date' => now()->subDays(5),
        ]);

        $paid = Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Pago,
            'due_date' => now()->subDays(5),
        ]);

        $component = Livewire::test('list-bills')
            ->set('periodType', 'geral')
            ->set('statusFilter', [BillStatus::Vencido->value, BillStatus::Pendente->value]);

        $ids = $component->viewData('bills')->pluck('id');

        $this->assertEqualsCanonicalizing([$pendingFuture->id, $overdue->id], $ids->all());
        $this->assertNotContains($paid->id, $ids);
    }

    public function test_sort_by_toggles_direction_and_reorders_bills(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $cheap = Bill::factory()->create(['user_id' => $user->id, 'value' => 10.00]);
        $expensive = Bill::factory()->create(['user_id' => $user->id, 'value' => 999.00]);

        $component = Livewire::test('list-bills')
            ->set('periodType', 'geral')
            ->call('sortBy', 'value');

        $this->assertSame('value', $component->get('sortColumn'));
        $this->assertSame('asc', $component->get('sortDirection'));
        $this->assertSame(
            [$cheap->id, $expensive->id],
            $component->viewData('bills')->pluck('id')->all()
        );

        $component->call('sortBy', 'value');

        $this->assertSame('desc', $component->get('sortDirection'));
        $this->assertSame(
            [$expensive->id, $cheap->id],
            $component->viewData('bills')->pluck('id')->all()
        );
    }

    public function test_sort_by_ignores_columns_outside_the_whitelist(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test('list-bills')
            ->call('sortBy', 'user_id; drop table bills');

        $this->assertSame('actual_due_date', $component->get('sortColumn'));
        $this->assertSame('asc', $component->get('sortDirection'));
    }

    public function test_selected_total_sums_only_the_checked_bills(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $a = Bill::factory()->create(['user_id' => $user->id, 'value' => 100.50]);
        $b = Bill::factory()->create(['user_id' => $user->id, 'value' => 49.50]);
        Bill::factory()->create(['user_id' => $user->id, 'value' => 999.99]);

        $component = Livewire::test('list-bills')
            ->set('periodType', 'geral')
            ->set('selectedBills', [$a->id, $b->id]);

        $this->assertSame(150.0, $component->viewData('selectedTotal'));

        $component->call('clearSelection');

        $this->assertSame(0.0, $component->viewData('selectedTotal'));
        $this->assertSame([], $component->get('selectedBills'));
    }

    public function test_selected_total_ignores_bills_outside_the_family_scope(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $this->actingAs($user);

        $mine = Bill::factory()->create(['user_id' => $user->id, 'value' => 75.00]);
        $theirs = Bill::factory()->create(['user_id' => $stranger->id, 'value' => 500.00]);

        $component = Livewire::test('list-bills')
            ->set('periodType', 'geral')
            ->set('selectedBills', [$mine->id, $theirs->id]);

        $this->assertSame(75.0, $component->viewData('selectedTotal'));
    }
}
