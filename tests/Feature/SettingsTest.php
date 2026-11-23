<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'Alex Morgan',
            'username' => 'alex.morgan',
            'email' => 'alex@fortvale.test',
            'theme' => 'system',
        ]);
        $this->actingAs($this->user);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Alex Morgan',
            'username' => 'alex.morgan',
            'email' => 'alex@fortvale.test',
            'job_title' => 'Operations lead',
            'theme' => 'system',
        ], $overrides);
    }

    public function test_renders_the_settings_page_at_the_username_address(): void
    {
        $this->get('/alex.morgan/settings')
            ->assertOk()
            ->assertSee('value="alex.morgan"', false)
            ->assertSee('Keyboard shortcuts');
    }

    public function test_forbids_viewing_or_saving_another_persons_settings(): void
    {
        $other = User::factory()->create(['username' => 'derek.lamar', 'name' => 'Derek Lamar']);

        $this->get('/derek.lamar/settings')->assertForbidden();
        $this->put('/derek.lamar/settings', $this->validPayload(['name' => 'Hacked']))->assertForbidden();

        $this->assertSame('Derek Lamar', $other->fresh()->name);
    }

    public function test_returns_404_for_an_unknown_username(): void
    {
        $this->get('/nobody.here/settings')->assertNotFound();
    }

    public function test_saving_updates_the_profile_and_theme(): void
    {
        $this->put('/alex.morgan/settings', $this->validPayload(['job_title' => 'Site director', 'theme' => 'dark']))
            ->assertRedirect('/alex.morgan/settings')
            ->assertSessionHas('status', 'Settings saved.');

        $this->user->refresh();
        $this->assertSame('Site director', $this->user->job_title);
        $this->assertSame('dark', $this->user->theme);

        $this->get('/alex.morgan/settings')->assertSee('data-theme="dark"', false);
    }

    public function test_changing_the_username_moves_the_settings_address(): void
    {
        $this->put('/alex.morgan/settings', $this->validPayload(['username' => 'Alex.M']))
            ->assertRedirect('/alex.m/settings');

        $this->assertSame('alex.m', $this->user->fresh()->username);
        $this->get('/alex.morgan/settings')->assertNotFound();
    }

    public function test_rejects_missing_required_fields(): void
    {
        $this->put('/alex.morgan/settings', [])
            ->assertSessionHasErrors(['name', 'username', 'email', 'theme']);
    }

    public function test_rejects_a_username_that_is_taken_reserved_or_badly_formed(): void
    {
        User::factory()->create(['username' => 'derek.lamar']);

        $this->put('/alex.morgan/settings', $this->validPayload(['username' => 'derek.lamar']))
            ->assertSessionHasErrors(['username' => 'Someone already has that username.']);

        $this->put('/alex.morgan/settings', $this->validPayload(['username' => 'ticket']))
            ->assertSessionHasErrors(['username' => 'That username is reserved. Please pick another one.']);

        $this->put('/alex.morgan/settings', $this->validPayload(['username' => 'alex..morgan']))
            ->assertSessionHasErrors(['username' => 'Use lowercase letters and numbers, separated by single dots, dashes or underscores.']);

        $this->assertSame('alex.morgan', $this->user->fresh()->username);
    }

    public function test_rejects_an_unknown_theme(): void
    {
        $this->put('/alex.morgan/settings', $this->validPayload(['theme' => 'neon']))
            ->assertSessionHasErrors('theme');
    }

    public function test_changing_the_password_needs_the_current_one(): void
    {
        $this->put(route('settings.password', $this->user), ['current_password' => 'wrong', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertSessionHasErrorsIn('password', 'current_password');

        $this->put(route('settings.password', $this->user), ['current_password' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertSessionHas('status', 'Password changed.');

        $this->assertTrue(Hash::check('new-password-1', $this->user->fresh()->password));
    }
}
