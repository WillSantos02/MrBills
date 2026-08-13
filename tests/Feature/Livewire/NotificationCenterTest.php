<?php

namespace Tests\Feature\Livewire;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\FamilyInvite;
use App\Models\User;
use App\Notifications\BillDueSoonNotification;
use App\Notifications\FamilyInviteAcceptedNotification;
use App\Notifications\FamilyInviteNotification;
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

    public function test_accepting_a_family_invite_adds_member_updates_owner_deletes_invite_and_notifies_owner(): void
    {
        $owner = User::factory()->create();
        $invitedUser = User::factory()->create();
        $invite = FamilyInvite::factory()->create(['owner_id' => $owner->id, 'invited_user_id' => $invitedUser->id]);
        $invitedUser->notify(new FamilyInviteNotification($invite));
        $notification = $invitedUser->notifications()->first();

        $this->actingAs($invitedUser);

        Livewire::test('notification-center')
            ->call('acceptInvite', $notification->id)
            ->assertViewHas('unreadCount', 0);

        $this->assertSame($owner->id, $invitedUser->fresh()->family_owner_id);
        $this->assertDatabaseMissing('family_invites', ['id' => $invite->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);

        $owner->refresh();
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(FamilyInviteAcceptedNotification::class, $owner->notifications()->first()->type);
    }

    public function test_accepting_own_pending_invite_cancels_invitee_users_own_outstanding_sent_invites(): void
    {
        $owner = User::factory()->create();
        $invitedUser = User::factory()->create();
        $invite = FamilyInvite::factory()->create(['owner_id' => $owner->id, 'invited_user_id' => $invitedUser->id]);
        $invitedUser->notify(new FamilyInviteNotification($invite));
        $notification = $invitedUser->notifications()->first();

        // $invitedUser is also a waiting owner with a pending invite of their own.
        $ownSentInvite = FamilyInvite::factory()->create(['owner_id' => $invitedUser->id]);

        $this->actingAs($invitedUser);

        Livewire::test('notification-center')
            ->call('acceptInvite', $notification->id);

        $this->assertDatabaseMissing('family_invites', ['id' => $ownSentInvite->id]);
    }

    public function test_rejecting_a_family_invite_deletes_the_invite_without_notifying_the_owner(): void
    {
        $owner = User::factory()->create();
        $invitedUser = User::factory()->create();
        $invite = FamilyInvite::factory()->create(['owner_id' => $owner->id, 'invited_user_id' => $invitedUser->id]);
        $invitedUser->notify(new FamilyInviteNotification($invite));
        $notification = $invitedUser->notifications()->first();

        $this->actingAs($invitedUser);

        Livewire::test('notification-center')
            ->call('rejectInvite', $notification->id)
            ->assertViewHas('unreadCount', 0);

        $this->assertNull($invitedUser->fresh()->family_owner_id);
        $this->assertDatabaseMissing('family_invites', ['id' => $invite->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
        $this->assertSame(0, $owner->fresh()->notifications()->count());
    }

    public function test_dismissing_the_accepted_confirmation_notification_deletes_it(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $owner->notify(new FamilyInviteAcceptedNotification($member));
        $notification = $owner->notifications()->first();

        $this->actingAs($owner);

        Livewire::test('notification-center')
            ->call('dismiss', $notification->id)
            ->assertViewHas('unreadCount', 0);

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_cannot_accept_or_reject_another_users_family_invite_notification(): void
    {
        $owner = User::factory()->create();
        $invitedUser = User::factory()->create();
        $invite = FamilyInvite::factory()->create(['owner_id' => $owner->id, 'invited_user_id' => $invitedUser->id]);
        $invitedUser->notify(new FamilyInviteNotification($invite));
        $notification = $invitedUser->notifications()->first();

        $intruder = User::factory()->create();
        $this->actingAs($intruder);

        try {
            Livewire::test('notification-center')
                ->call('acceptInvite', $notification->id);

            $this->fail('Expected a ModelNotFoundException when acting on another user\'s notification.');
        } catch (ModelNotFoundException) {
            // esperado: a notificação não pertence ao usuário autenticado.
        }

        $this->assertNull($invitedUser->fresh()->family_owner_id);
        $this->assertDatabaseHas('family_invites', ['id' => $invite->id]);
    }
}
