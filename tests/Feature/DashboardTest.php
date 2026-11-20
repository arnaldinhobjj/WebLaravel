<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardTest extends TestCase
{
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
}
