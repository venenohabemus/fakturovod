<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_sends_guests_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_root_sends_authenticated_users_to_invoices(): void
    {
        $this->actingAs(User::factory()->make())
            ->get('/')
            ->assertRedirect('/faktury');
    }
}
