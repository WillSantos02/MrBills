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
            ->set('statusFilter', (string) BillStatus::Vencido->value);

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
            ->set('statusFilter', (string) BillStatus::Pendente->value);

        $ids = $component->viewData('bills')->pluck('id');

        $this->assertEqualsCanonicalizing([$pendingFuture->id], $ids->all());
        $this->assertNotContains($overdue->id, $ids);
    }
}
