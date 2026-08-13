<?php

namespace Tests\Feature;

use App\Models\FamilyInvite;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FamilyInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_only_have_one_pending_invite_at_a_time(): void
    {
        $invitedUser = User::factory()->create();
        FamilyInvite::factory()->create(['invited_user_id' => $invitedUser->id]);

        $this->expectException(QueryException::class);

        FamilyInvite::factory()->create(['invited_user_id' => $invitedUser->id]);
    }

    public function test_family_owner_and_member_relations_resolve_correctly(): void
    {
        $owner = User::factory()->create();
        $memberOne = User::factory()->create(['family_owner_id' => $owner->id]);
        $memberTwo = User::factory()->create(['family_owner_id' => $owner->id]);

        $this->assertSame(2, $owner->familyMembers()->count());
        $this->assertTrue($owner->familyMembers()->pluck('id')->contains($memberOne->id));
        $this->assertTrue($owner->familyMembers()->pluck('id')->contains($memberTwo->id));
        $this->assertSame($owner->id, $memberOne->familyOwner->id);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function remainingSlotsProvider(): array
    {
        return [
            'no members, no invites' => [0, 0, 2],
            'one accepted member' => [1, 0, 1],
            'one member and one pending invite' => [1, 1, 0],
            'two accepted members' => [2, 0, 0],
        ];
    }

    #[DataProvider('remainingSlotsProvider')]
    public function test_remaining_family_slots_accounts_for_members_and_pending_invites(
        int $membersCount,
        int $pendingInvitesCount,
        int $expectedRemaining,
    ): void {
        $owner = User::factory()->create();

        User::factory()->count($membersCount)->create(['family_owner_id' => $owner->id]);

        for ($i = 0; $i < $pendingInvitesCount; $i++) {
            FamilyInvite::factory()->create(['owner_id' => $owner->id]);
        }

        $this->assertSame($expectedRemaining, $owner->remainingFamilySlots());
    }
}
