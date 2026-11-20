<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_screen_renders_all_sections(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Fort Vale')
            ->assertSeeInOrder(['Favorites', 'Quality', 'Nuclear', 'Dispatch', 'CCTV', 'Health'])
            ->assertSeeInOrder(['Actions', 'Display sales', 'Request printer support'])
            ->assertSeeInOrder(['Mentions', 'Derek Lamar', '#37941', 'Could you please confirm you have']);
    }

    public function test_a_module_can_be_removed_from_and_added_to_favorites(): void
    {
        $this->post('/favorites/nuclear')->assertRedirect();
        $this->assertSame(['quality', 'dispatch', 'cctv', 'health'], session('favorites'));

        $this->post('/favorites/fleet')->assertSessionHas('status', 'Fleet added to favorites.');
        $this->assertContains('fleet', session('favorites'));
    }

    public function test_unknown_module_returns_404(): void
    {
        $this->post('/favorites/unknown')->assertNotFound();
    }

    public function test_running_an_action_records_the_run(): void
    {
        $this->post('/actions/display-sales/run')
            ->assertRedirect()
            ->assertSessionHas('status', '“Display sales” started.');

        $this->get('/')->assertSee('Last run');
    }

    public function test_mentions_can_be_resolved_dismissed_and_restored(): void
    {
        $this->post('/mentions/37941/resolve')->assertSessionHas('status', 'Mention on #37941 marked as done.');
        $this->get('/')->assertDontSee('class="ticket">#37941', false);

        $this->post('/mentions/37902/dismiss');
        $this->post('/mentions/37855/dismiss');
        $this->post('/mentions/37810/resolve');
        $this->get('/')->assertSee('You’re all caught up.')->assertSee('Restore 4 mentions');

        $this->post('/mentions/restore');
        $this->get('/')->assertSee('#37941');
    }

    public function test_printer_support_action_opens_a_prefilled_ticket(): void
    {
        $this->post('/actions/request-printer-support/run')
            ->assertRedirect(route('tickets.create', ['subject' => 'Printer support needed', 'category' => 'it']));
    }

    public function test_title_bar_has_only_the_profile_menu_with_settings_and_actions_links(): void
    {
        $user = User::factory()->create(['name' => 'Alex Morgan', 'username' => 'alex.morgan', 'job_title' => 'Operations lead']);
        User::factory()->create(['name' => 'Derek Lamar']);

        $this->withSession(['acting_user_id' => $user->id])
            ->get('/')
            ->assertDontSee('title="Settings"', false)
            ->assertDontSee('title="More"', false)
            ->assertSee('href="'.route('settings.edit', $user).'"', false)
            ->assertSee('href="'.route('actions.index', $user).'"', false)
            ->assertSeeInOrder(['Alex Morgan', 'Operations lead', 'Assigned to me', 'My actions', 'Settings', 'Switch user', 'Derek Lamar']);

        $this->assertSame(url('/alex.morgan/settings'), route('settings.edit', $user));
    }

    public function test_home_screen_shows_the_users_own_actions_that_are_on_home(): void
    {
        $user = User::factory()->create();
        Action::factory()->for($user)->create(['name' => 'Open urgent queue']);
        Action::factory()->for($user)->create(['name' => 'Hidden shortcut', 'show_on_home' => false]);
        Action::factory()->create(['name' => 'Someone elses action']);

        $this->withSession(['acting_user_id' => $user->id])
            ->get('/')
            ->assertSee('Open urgent queue')
            ->assertDontSee('Hidden shortcut')
            ->assertDontSee('Someone elses action')
            ->assertSeeInOrder(['Open urgent queue', 'Display sales']);
    }

    public function test_reset_home_restores_defaults(): void
    {
        $this->post('/favorites/nuclear');
        $this->post('/mentions/37941/dismiss');

        $this->post('/settings/reset-home')->assertRedirect('/');

        $this->assertNull(session('favorites'));
        $this->assertNull(session('handled_mentions'));
    }
}
