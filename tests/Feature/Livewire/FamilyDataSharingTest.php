<?php

namespace Tests\Feature\Livewire;

use App\Models\Bill;
use App\Models\Category;
use App\Models\Income;
use App\Models\IncomeCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FamilyDataSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_member_sees_bills_created_by_another_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);
        $bill = Bill::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($member);

        $ids = Livewire::test('list-bills')
            ->set('periodType', 'geral')
            ->viewData('bills')
            ->pluck('id');

        $this->assertTrue($ids->contains($bill->id));
    }

    public function test_family_member_sees_incomes_created_by_another_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);
        $income = Income::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($member);

        $ids = Livewire::test('list-income')
            ->set('periodType', 'geral')
            ->viewData('incomes')
            ->pluck('id');

        $this->assertTrue($ids->contains($income->id));
    }

    public function test_family_member_sees_categories_created_by_another_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);
        $category = Category::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($member);

        $ids = Livewire::test('list-categories')->viewData('categories')->pluck('id');

        $this->assertTrue($ids->contains($category->id));
    }

    public function test_family_member_sees_income_categories_created_by_another_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);
        $category = IncomeCategory::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($member);

        $ids = Livewire::test('list-income-categories')->viewData('incomeCategories')->pluck('id');

        $this->assertTrue($ids->contains($category->id));
    }

    public function test_family_member_can_edit_a_bill_created_by_another_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);
        $bill = Bill::factory()->create(['user_id' => $owner->id, 'category_id' => null, 'description' => 'Original']);

        $this->actingAs($member);

        Livewire::test('list-bills')
            ->call('editBill', $bill->id)
            ->set('edit_description', 'Editado pelo membro')
            ->call('updateBill');

        $this->assertSame('Editado pelo membro', $bill->fresh()->description);
    }

    public function test_family_member_can_delete_a_category_created_by_another_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);
        $category = Category::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($member);

        Livewire::test('list-categories')
            ->call('askDeleteCategory', $category->id)
            ->call('confirmDeleteCategory');

        $this->assertNull(Category::find($category->id));
    }

    public function test_unrelated_users_do_not_see_each_others_bills(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $bill = Bill::factory()->create(['user_id' => $userB->id]);

        $this->actingAs($userA);

        $ids = Livewire::test('list-bills')
            ->set('periodType', 'geral')
            ->viewData('bills')
            ->pluck('id');

        $this->assertFalse($ids->contains($bill->id));
    }

    public function test_unrelated_user_cannot_edit_anothers_bill(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $bill = Bill::factory()->create(['user_id' => $userB->id]);

        $this->actingAs($userA);

        try {
            Livewire::test('list-bills')->call('editBill', $bill->id);

            $this->fail('Expected a ModelNotFoundException when editing another family\'s bill.');
        } catch (ModelNotFoundException) {
            // esperado: a conta não pertence ao círculo familiar do usuário autenticado.
        }

        $this->assertSame($userB->id, $bill->fresh()->user_id);
    }

    public function test_creating_a_category_with_a_name_already_used_by_a_family_member_fails_validation(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);
        Category::factory()->create(['user_id' => $owner->id, 'name' => 'Mercado']);

        $this->actingAs($member);

        Livewire::test('create-category')
            ->set('type', 'despesa')
            ->set('name', 'Mercado')
            ->call('save')
            ->assertHasErrors(['name' => 'unique']);
    }

    public function test_creating_a_category_with_a_name_used_by_an_unrelated_user_is_allowed(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Category::factory()->create(['user_id' => $userB->id, 'name' => 'Mercado']);

        $this->actingAs($userA);

        Livewire::test('create-category')
            ->set('type', 'despesa')
            ->set('name', 'Mercado')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_dashboard_sums_the_whole_family_by_default(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);

        Income::factory()->create(['user_id' => $owner->id, 'value' => 100, 'date' => now()]);
        Income::factory()->create(['user_id' => $member->id, 'value' => 50, 'date' => now()]);

        $this->actingAs($owner);

        $totalCarteira = Livewire::test('dashboard-summary')->viewData('totalCarteira');

        $this->assertEquals(150, $totalCarteira);
    }

    public function test_dashboard_member_filter_restricts_totals_to_one_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['family_owner_id' => $owner->id]);

        Income::factory()->create(['user_id' => $owner->id, 'value' => 100, 'date' => now()]);
        Income::factory()->create(['user_id' => $member->id, 'value' => 50, 'date' => now()]);

        $this->actingAs($owner);

        $totalCarteira = Livewire::test('dashboard-summary')
            ->set('memberFilter', (string) $member->id)
            ->viewData('totalCarteira');

        $this->assertEquals(50, $totalCarteira);
    }

    public function test_cannot_create_a_bill_with_a_category_from_an_unrelated_family(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $foreignCategory = Category::factory()->create(['user_id' => $userB->id]);

        $this->actingAs($userA);

        Livewire::test('create-bill')
            ->set('description', 'Aluguel')
            ->set('value', '100')
            ->set('due_date', now()->addDays(5)->toDateString())
            ->set('category_id', $foreignCategory->id)
            ->call('save')
            ->assertHasErrors(['category_id']);
    }

    public function test_cannot_edit_a_bill_into_a_category_from_an_unrelated_family(): void
    {
        $userA = User::factory()->create();
        $bill = Bill::factory()->create(['user_id' => $userA->id]);

        $userB = User::factory()->create();
        $foreignCategory = Category::factory()->create(['user_id' => $userB->id]);

        $this->actingAs($userA);

        Livewire::test('list-bills')
            ->call('editBill', $bill->id)
            ->set('edit_category_id', $foreignCategory->id)
            ->call('updateBill')
            ->assertHasErrors(['edit_category_id']);

        $this->assertNotSame($foreignCategory->id, $bill->fresh()->category_id);
    }

    public function test_cannot_create_an_income_with_a_category_from_an_unrelated_family(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $foreignCategory = IncomeCategory::factory()->create(['user_id' => $userB->id]);

        $this->actingAs($userA);

        Livewire::test('create-income')
            ->set('description', 'Salário')
            ->set('value', '100')
            ->set('date', now()->toDateString())
            ->set('income_category_id', $foreignCategory->id)
            ->call('save')
            ->assertHasErrors(['income_category_id']);
    }

    public function test_cannot_edit_an_income_into_a_category_from_an_unrelated_family(): void
    {
        $userA = User::factory()->create();
        $income = Income::factory()->create(['user_id' => $userA->id]);

        $userB = User::factory()->create();
        $foreignCategory = IncomeCategory::factory()->create(['user_id' => $userB->id]);

        $this->actingAs($userA);

        Livewire::test('list-income')
            ->call('editIncome', $income->id)
            ->set('edit_income_category_id', $foreignCategory->id)
            ->call('updateIncome')
            ->assertHasErrors(['edit_income_category_id']);

        $this->assertNotSame($foreignCategory->id, $income->fresh()->income_category_id);
    }
}
