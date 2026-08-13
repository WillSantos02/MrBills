<?php

namespace Tests\Feature\Livewire;

use App\Models\FamilyInvite;
use App\Models\User;
use App\Notifications\FamilyInviteNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class SendFamilyInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_invite_to_a_registered_user_and_notifies_them(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $invitedUser = User::factory()->create();
        $this->actingAs($owner);

        Livewire::test('send-family-invite')
            ->set('email', $invitedUser->email)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('family_invites', [
            'owner_id' => $owner->id,
            'invited_user_id' => $invitedUser->id,
        ]);

        Notification::assertSentTo($invitedUser, FamilyInviteNotification::class);
    }

    public function test_rejects_email_that_does_not_belong_to_a_registered_user(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        Livewire::test('send-family-invite')
            ->set('email', 'nao-cadastrado@example.com')
            ->call('save')
            ->assertHasErrors(['email']);

        $this->assertDatabaseCount('family_invites', 0);
    }

    public function test_rejects_self_invite(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        Livewire::test('send-family-invite')
            ->set('email', $owner->email)
            ->call('save')
            ->assertHasErrors(['email']);

        $this->assertDatabaseCount('family_invites', 0);
    }

    public function test_rejects_invitee_already_member_of_another_family(): void
    {
        $otherOwner = User::factory()->create();
        $invitedUser = User::factory()->create(['family_owner_id' => $otherOwner->id]);

        $owner = User::factory()->create();
        $this->actingAs($owner);

        Livewire::test('send-family-invite')
            ->set('email', $invitedUser->email)
            ->call('save')
            ->assertHasErrors(['email']);

        $this->assertDatabaseCount('family_invites', 0);
    }

    public function test_rejects_invitee_who_owns_another_family_with_members(): void
    {
        $invitedUser = User::factory()->create();
        User::factory()->create(['family_owner_id' => $invitedUser->id]);

        $owner = User::factory()->create();
        $this->actingAs($owner);

        Livewire::test('send-family-invite')
            ->set('email', $invitedUser->email)
            ->call('save')
            ->assertHasErrors(['email']);

        $this->assertDatabaseCount('family_invites', 0);
    }

    public function test_rejects_duplicate_pending_invite_to_the_same_user(): void
    {
        $owner = User::factory()->create();
        $invitedUser = User::factory()->create();
        FamilyInvite::factory()->create(['owner_id' => $owner->id, 'invited_user_id' => $invitedUser->id]);

        $this->actingAs($owner);

        Livewire::test('send-family-invite')
            ->set('email', $invitedUser->email)
            ->call('save')
            ->assertHasErrors(['email']);

        $this->assertDatabaseCount('family_invites', 1);
    }

    public function test_rejects_invite_once_owner_has_reached_the_two_member_cap(): void
    {
        $owner = User::factory()->create();
        User::factory()->count(2)->create(['family_owner_id' => $owner->id]);

        $invitedUser = User::factory()->create();
        $this->actingAs($owner);

        Livewire::test('send-family-invite')
            ->set('email', $invitedUser->email)
            ->call('save')
            ->assertHasErrors(['email']);

        $this->assertDatabaseCount('family_invites', 0);
    }

    public function test_member_of_another_family_cannot_send_invites(): void
    {
        $otherOwner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $otherOwner->id]);
        $invitedUser = User::factory()->create();

        $this->actingAs($member);

        Livewire::test('send-family-invite')
            ->set('email', $invitedUser->email)
            ->call('save')
            ->assertStatus(403);

        $this->assertDatabaseCount('family_invites', 0);
    }
}
