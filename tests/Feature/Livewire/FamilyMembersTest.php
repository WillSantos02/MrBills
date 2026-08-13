<?php

namespace Tests\Feature\Livewire;

use App\Models\FamilyInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FamilyMembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_view_lists_accepted_members_and_pending_invites(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id, 'name' => 'Membro Aceito']);
        $pending = User::factory()->create(['name' => 'Convidado Pendente']);
        FamilyInvite::factory()->create(['owner_id' => $owner->id, 'invited_user_id' => $pending->id]);

        $this->actingAs($owner);

        Livewire::test('family-members')
            ->assertViewHas('role', 'owner')
            ->assertSee('Membro Aceito')
            ->assertSee('Convidado Pendente');
    }

    public function test_member_view_shows_owner_and_sibling_members(): void
    {
        $owner = User::factory()->create(['name' => 'Dono da Família']);
        $member = User::factory()->create(['family_owner_id' => $owner->id]);
        $sibling = User::factory()->create(['family_owner_id' => $owner->id, 'name' => 'Outro Membro']);

        $this->actingAs($member);

        Livewire::test('family-members')
            ->assertViewHas('role', 'member')
            ->assertSee('Dono da Família')
            ->assertSee('Outro Membro');
    }

    public function test_cancel_invite_only_deletes_invites_owned_by_the_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $invitedUser = User::factory()->create();
        $invite = FamilyInvite::factory()->create(['owner_id' => $owner->id, 'invited_user_id' => $invitedUser->id]);

        $intruder = User::factory()->create();
        $this->actingAs($intruder);

        Livewire::test('family-members')
            ->call('cancelInvite', $invite->id);

        $this->assertDatabaseHas('family_invites', ['id' => $invite->id]);

        $this->actingAs($owner);

        Livewire::test('family-members')
            ->call('cancelInvite', $invite->id);

        $this->assertDatabaseMissing('family_invites', ['id' => $invite->id]);
    }
}
