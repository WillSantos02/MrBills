<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\User;
use App\Notifications\BillDueSoonNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

class SendBillDueSoonNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Terça-feira fixa: evita que os offsets de dias usados abaixo caiam num fim
        // de semana e sejam deslocados pelo rollover de vencimento do Bill (ver BillTest).
        Carbon::setTestNow(Carbon::parse('2026-08-11 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_notifies_a_pending_bill_due_within_the_next_three_days(): void
    {
        $user = User::factory()->create();

        $bill = Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Pendente,
            'due_date' => now()->addDays(2),
        ]);

        $this->artisan('notifications:send-bill-due-soon');

        $this->assertSame(1, $user->notifications()->count());

        $notification = $user->notifications()->first();
        $this->assertSame(BillDueSoonNotification::class, $notification->type);
        $this->assertSame($bill->id, $notification->data['bill_id']);
        $this->assertSame($bill->actual_due_date->toDateString(), $notification->data['due_date']);
        $this->assertSame(2, $notification->data['days_until_due']);

        $this->assertSame(now()->toDateString(), $bill->fresh()->last_due_soon_notified_at->toDateString());
    }

    public function test_does_not_duplicate_when_run_twice_on_the_same_day(): void
    {
        $user = User::factory()->create();

        Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Pendente,
            'due_date' => now()->addDay(),
        ]);

        $this->artisan('notifications:send-bill-due-soon');
        $this->artisan('notifications:send-bill-due-soon');

        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_notifies_again_the_next_day_if_still_pending(): void
    {
        $user = User::factory()->create();

        $bill = Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Pendente,
            'due_date' => now()->addDays(2),
        ]);

        $this->artisan('notifications:send-bill-due-soon');

        // Simula "Lembrar depois": marca a notificação de hoje como lida, sem mexer na conta.
        $user->notifications()->first()->markAsRead();

        // Simula o dia seguinte "resetando" o dedupe.
        $bill->update(['last_due_soon_notified_at' => now()->subDay()->toDateString()]);

        $this->artisan('notifications:send-bill-due-soon');

        $this->assertSame(2, $user->notifications()->count());
    }

    public function test_does_not_notify_a_bill_outside_the_three_day_window(): void
    {
        $user = User::factory()->create();

        Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Pendente,
            'due_date' => now()->addDays(10),
        ]);

        $this->artisan('notifications:send-bill-due-soon');

        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_does_not_notify_a_bill_that_is_already_paid(): void
    {
        $user = User::factory()->create();

        Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Pago,
            'due_date' => now()->addDay(),
        ]);

        $this->artisan('notifications:send-bill-due-soon');

        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_does_not_notify_a_bill_that_is_already_overdue(): void
    {
        $user = User::factory()->create();

        Bill::factory()->create([
            'user_id' => $user->id,
            'status' => BillStatus::Pendente,
            'due_date' => now()->subDays(2),
        ]);

        $this->artisan('notifications:send-bill-due-soon');

        $this->assertSame(0, DatabaseNotification::count());
    }
}
