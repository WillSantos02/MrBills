<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangelogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_changelog_page_renders_the_changelog_file(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('changelog'))
            ->assertOk()
            ->assertSee('Versão 1.1.0')
            ->assertSee('Conta Fixa');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('changelog'))->assertRedirect(route('login'));
    }
}
