<?php

namespace Tests\Feature\Livewire;

use App\Models\Bill;
use App\Models\Category;
use App\Models\Income;
use App\Models\IncomeCategory;
use App\Models\OwnershipTransferRequest;
use App\Models\User;
use App\Notifications\OwnershipTransferAcceptedNotification;
use App\Notifications\OwnershipTransferRejectedNotification;
use App\Notifications\OwnershipTransferRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_request_ownership_transfer_and_member_is_notified(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);

        $this->actingAs($owner);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('selectedMemberId', (string) $member->id)
            ->call('requestOwnershipTransfer')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('ownership_transfer_requests', [
            'from_user_id' => $owner->id,
            'to_user_id' => $member->id,
        ]);

        $this->assertSame(1, $member->fresh()->unreadNotifications()
            ->where('type', OwnershipTransferRequestNotification::class)->count());
    }

    public function test_cannot_request_transfer_to_a_user_outside_the_family(): void
    {
        $owner = User::factory()->create();
        User::factory()->create(['family_owner_id' => $owner->id]);
        $outsider = User::factory()->create();

        $this->actingAs($owner);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('selectedMemberId', (string) $outsider->id)
            ->call('requestOwnershipTransfer')
            ->assertHasErrors(['selectedMemberId']);

        $this->assertDatabaseMissing('ownership_transfer_requests', ['from_user_id' => $owner->id]);
    }

    public function test_owner_with_members_cannot_hard_delete_account(): void
    {
        $owner = User::factory()->create();
        User::factory()->create(['family_owner_id' => $owner->id]);

        $this->actingAs($owner);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors(['password']);

        $this->assertNotNull($owner->fresh());
    }

    public function test_accepting_transfer_reassigns_data_and_family_structure(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);

        $category = Category::factory()->create(['user_id' => $owner->id]);
        $bill = Bill::factory()->create(['user_id' => $owner->id, 'category_id' => $category->id]);

        $incomeCategory = IncomeCategory::factory()->create(['user_id' => $owner->id]);
        $income = Income::factory()->create(['user_id' => $owner->id, 'income_category_id' => $incomeCategory->id]);

        $this->actingAs($owner);
        Livewire::test('pages::settings.delete-user-modal')
            ->set('selectedMemberId', (string) $member->id)
            ->call('requestOwnershipTransfer');

        $transferRequest = OwnershipTransferRequest::where('from_user_id', $owner->id)->firstOrFail();

        $this->actingAs($member);
        $member->notify(new OwnershipTransferRequestNotification($transferRequest));
        $notification = $member->fresh()->unreadNotifications()->firstOrFail();

        Livewire::test('notification-center')
            ->call('acceptOwnershipTransfer', $notification->id)
            ->assertHasNoErrors();

        $this->assertSame($member->id, $bill->fresh()->user_id);
        $this->assertSame($member->id, $income->fresh()->user_id);
        $this->assertSame($member->id, $category->fresh()->user_id);
        $this->assertSame($member->id, $incomeCategory->fresh()->user_id);

        $this->assertNull($member->fresh()->family_owner_id);
        $this->assertSame($member->id, $owner->fresh()->family_owner_id);
        $this->assertSame(1, $member->fresh()->familyMembers()->count());
        // O ex-dono virou um membro comum — não fica mais preso pelo guard de "dono com membros".
        $this->assertSame(0, $owner->fresh()->familyMembers()->count());

        $this->assertDatabaseMissing('ownership_transfer_requests', ['id' => $transferRequest->id]);
        $this->assertSame(1, $owner->fresh()->unreadNotifications()
            ->where('type', OwnershipTransferAcceptedNotification::class)->count());
    }

    public function test_accepting_transfer_merges_categories_with_the_same_name(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);

        $ownerCategory = Category::factory()->create(['user_id' => $owner->id, 'name' => 'Mercado']);
        $ownerBill = Bill::factory()->create(['user_id' => $owner->id, 'category_id' => $ownerCategory->id]);

        $memberCategory = Category::factory()->create(['user_id' => $member->id, 'name' => 'Mercado']);
        $memberBill = Bill::factory()->create(['user_id' => $member->id, 'category_id' => $memberCategory->id]);

        $transferRequest = OwnershipTransferRequest::factory()->create([
            'from_user_id' => $owner->id,
            'to_user_id' => $member->id,
        ]);

        $this->actingAs($member);
        $member->notify(new OwnershipTransferRequestNotification($transferRequest));
        $notification = $member->fresh()->unreadNotifications()->firstOrFail();

        Livewire::test('notification-center')->call('acceptOwnershipTransfer', $notification->id);

        $this->assertDatabaseMissing('categories', ['id' => $ownerCategory->id]);
        $this->assertSame(1, Category::where('user_id', $member->id)->where('name', 'Mercado')->count());
        $this->assertSame($memberCategory->id, $ownerBill->fresh()->category_id);
        $this->assertSame($memberCategory->id, $memberBill->fresh()->category_id);
    }

    public function test_rejecting_transfer_stamps_declined_and_notifies_owner(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);

        $transferRequest = OwnershipTransferRequest::factory()->create([
            'from_user_id' => $owner->id,
            'to_user_id' => $member->id,
        ]);

        $this->actingAs($member);
        $member->notify(new OwnershipTransferRequestNotification($transferRequest));
        $notification = $member->fresh()->unreadNotifications()->firstOrFail();

        Livewire::test('notification-center')->call('rejectOwnershipTransfer', $notification->id);

        $this->assertDatabaseMissing('ownership_transfer_requests', ['id' => $transferRequest->id]);
        $this->assertNotNull($owner->fresh()->family_transfer_declined_at);
        $this->assertSame(1, $owner->fresh()->unreadNotifications()
            ->where('type', OwnershipTransferRejectedNotification::class)->count());
    }

    public function test_owner_can_soft_delete_after_transfer_declined_and_data_stays_visible(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);
        $owner->update(['family_transfer_declined_at' => now()]);

        $bill = Bill::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('softDeleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull(User::find($owner->id));
        $this->assertNotNull(User::withTrashed()->find($owner->id));
        $this->assertDatabaseHas('bills', ['id' => $bill->id, 'user_id' => $owner->id]);

        $this->actingAs($member);
        $ids = Livewire::test('list-bills')
            ->set('periodType', 'geral')
            ->viewData('bills')
            ->pluck('id');

        $this->assertTrue($ids->contains($bill->id));
    }

    public function test_soft_deleted_user_cannot_authenticate(): void
    {
        $owner = User::factory()->create();
        User::factory()->create(['family_owner_id' => $owner->id]);
        $owner->update(['family_transfer_declined_at' => now()]);

        $this->actingAs($owner);
        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('softDeleteUser');

        $response = $this->post(route('login.store'), [
            'email' => $owner->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrorsIn('email');
        $this->assertGuest();
    }

    public function test_last_active_member_deleting_account_purges_the_soft_deleted_former_owner(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);
        $owner->update(['family_transfer_declined_at' => now()]);

        $ownerBill = Bill::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner);
        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('softDeleteUser');

        $this->actingAs($member);
        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull(User::withTrashed()->find($owner->id));
        $this->assertDatabaseMissing('bills', ['id' => $ownerBill->id]);
        $this->assertNull(User::withTrashed()->find($member->id));
    }

    public function test_cancel_pending_transfer_request(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);

        $transferRequest = OwnershipTransferRequest::factory()->create([
            'from_user_id' => $owner->id,
            'to_user_id' => $member->id,
        ]);

        $this->actingAs($owner);
        Livewire::test('pages::settings.delete-user-modal')->call('cancelOwnershipTransferRequest');

        $this->assertDatabaseMissing('ownership_transfer_requests', ['id' => $transferRequest->id]);
    }
}
