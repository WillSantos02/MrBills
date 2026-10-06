<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
    }

    public function test_unknown_route_renders_branded_error_page(): void
    {
        $this->get('/rota-que-nao-existe')
            ->assertNotFound()
            ->assertSee('erro 404')
            ->assertSee('Página não encontrada');
    }
}
