<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_username_fits_the_column_for_a_long_name(): void
    {
        $name = 'Maximiliana Alexandrina Montgomery-Fitzwilliam';
        User::factory()->create(['username' => 'maximiliana.alexandrina.mo']);

        $username = User::uniqueUsernameFor($name);

        $this->assertSame('maximiliana.alexandrina.mo2', $username);
        $this->assertLessThanOrEqual(30, strlen($username));
    }

    public function test_generated_username_avoids_reserved_words(): void
    {
        $this->assertSame('ticket.user', User::uniqueUsernameFor('Ticket'));
    }

    public function test_creating_a_user_without_a_username_generates_one(): void
    {
        $user = User::create(['name' => 'Jo Bloggs', 'email' => 'jo@fortvale.test', 'password' => 'secret-password']);

        $this->assertSame('jo.bloggs', $user->username);
    }
}
