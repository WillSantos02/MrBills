<?php

namespace Tests\Feature\Livewire;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\User;
use App\Notifications\BillDueSoonNotification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_unread_notifications_with_the_correct_badge_count(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $bill = Bill::factory()->create(['user_id' => $user->id, 'status' => BillStatus::Pendente]);
        $user->notify(new BillDueSoonNotification($bill));

        Livewire::test('notification-center')
            ->assertViewHas('unreadCount', 1)
            ->assertSee($bill->display_description);
    }

    public function test_marking_a_bill_as_paid_updates_the_bill_and_hides_the_notification(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $bill = Bill::factory()->create(['user_id' => $user->id, 'status' => BillStatus::Pendente]);
        $user->notify(new BillDueSoonNotification($bill));
        $notification = $user->notifications()->first();

        Livewire::test('notification-center')
            ->call('markBillAsPaid', $notification->id)
            ->assertViewHas('unreadCount', 0);

        $this->assertSame(BillStatus::Pago, $bill->fresh()->status);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_remind_later_hides_the_notification_without_changing_the_bill(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $bill = Bill::factory()->create(['user_id' => $user->id, 'status' => BillStatus::Pendente]);
        $user->notify(new BillDueSoonNotification($bill));
        $notification = $user->notifications()->first();

        Livewire::test('notification-center')
            ->call('remindLater', $notification->id)
            ->assertViewHas('unreadCount', 0);

        $this->assertSame(BillStatus::Pendente, $bill->fresh()->status);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_cannot_act_on_another_users_notification(): void
    {
        $owner = User::factory()->create();
        $bill = Bill::factory()->create(['user_id' => $owner->id, 'status' => BillStatus::Pendente]);
        $owner->notify(new BillDueSoonNotification($bill));
        $notification = $owner->notifications()->first();

        $intruder = User::factory()->create();
        $this->actingAs($intruder);

        try {
            Livewire::test('notification-center')
                ->call('markBillAsPaid', $notification->id);

            $this->fail('Expected a ModelNotFoundException when acting on another user\'s notification.');
        } catch (ModelNotFoundException) {
            // esperado: a notificação não pertence ao usuário autenticado.
        }

        $this->assertSame(BillStatus::Pendente, $bill->fresh()->status);
    }
}
