<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $user = User::factory()->create(['username' => 'alex.morgan']);

        foreach (['/', '/ticket', '/tags', '/alex.morgan/settings', '/actions/shared/x'] as $page) {
            $this->get($page)->assertRedirect('/login');
        }
        $this->post(route('tickets.store'))->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Sign in');
        $this->assertGuest();
        $this->assertNotNull($user);
    }

    public function test_signing_in_with_the_right_password(): void
    {
        $user = User::factory()->create(['email' => 'alex@fortvale.test']);

        $this->get('/ticket');
        $this->post('/login', ['email' => 'alex@fortvale.test', 'password' => 'password'])->assertRedirect('/ticket');

        $this->assertAuthenticatedAs($user);
        $this->get('/login')->assertRedirect();
    }

    public function test_a_wrong_password_is_refused_and_attempts_are_limited(): void
    {
        User::factory()->create(['email' => 'alex@fortvale.test']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', ['email' => 'alex@fortvale.test', 'password' => 'wrong'])
                ->assertSessionHasErrors(['email' => 'That email and password don’t match.']);
        }

        $this->post('/login', ['email' => 'alex@fortvale.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertStringStartsWith('Too many attempts.', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_signing_out(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }
}
