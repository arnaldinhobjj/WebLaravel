<?php

namespace Tests\Feature;

use App\Enums\ActionType;
use App\Enums\TicketPriority;
use App\Models\Action;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['username' => 'alex.morgan']);
        $this->withSession(['acting_user_id' => $this->user->id]);
    }

    public function test_lists_only_the_current_users_actions(): void
    {
        Action::factory()->for($this->user)->create(['name' => 'My queue']);
        Action::factory()->create(['name' => 'Not mine']);

        $this->get('/alex.morgan/actions')
            ->assertOk()
            ->assertSee('My queue')
            ->assertDontSee('Not mine');
    }

    public function test_shows_an_empty_state_without_actions(): void
    {
        $this->get('/alex.morgan/actions')->assertSee('You haven’t made any actions yet.');
    }

    public function test_creates_a_link_action(): void
    {
        $this->post('/alex.morgan/actions', [
            'name' => 'Urgent queue',
            'description' => 'All urgent tickets',
            'type' => 'link',
            'url' => '/ticket?priority=urgent',
            'ticket_subject' => 'Ignored for links',
            'show_on_home' => '1',
        ])->assertRedirect('/alex.morgan/actions')->assertSessionHas('status', '“Urgent queue” created.');

        $action = $this->user->actions()->sole();
        $this->assertSame(ActionType::Link, $action->type);
        $this->assertSame('/ticket?priority=urgent', $action->url);
        $this->assertNull($action->ticket_subject);
        $this->assertTrue($action->show_on_home);
    }

    public function test_creates_a_ticket_template_action_hidden_from_home(): void
    {
        $this->post('/alex.morgan/actions', [
            'name' => 'Camera fault',
            'type' => 'ticket',
            'url' => '/ignored',
            'ticket_subject' => 'Camera fault',
            'ticket_category' => 'cctv',
            'ticket_priority' => 'urgent',
            'ticket_description' => 'Which camera?',
            'show_on_home' => '0',
        ])->assertRedirect('/alex.morgan/actions');

        $action = $this->user->actions()->sole();
        $this->assertSame(ActionType::Ticket, $action->type);
        $this->assertSame(TicketPriority::Urgent, $action->ticket_priority);
        $this->assertNull($action->url);
        $this->assertFalse($action->show_on_home);
    }

    public function test_rejects_an_action_without_its_required_fields(): void
    {
        $this->post('/alex.morgan/actions', [])->assertSessionHasErrors(['name', 'type']);

        $this->post('/alex.morgan/actions', ['name' => 'Link', 'type' => 'link'])
            ->assertSessionHasErrors(['url' => 'Enter the page or website this action opens.']);

        $this->post('/alex.morgan/actions', ['name' => 'Ticket', 'type' => 'ticket'])
            ->assertSessionHasErrors(['ticket_subject', 'ticket_category', 'ticket_priority']);

        $this->assertSame(0, Action::count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeLinks(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'protocol-relative' => ['//evil.example.com'],
            'plain text' => ['not a link'],
            'ftp scheme' => ['ftp://files.example.com'],
        ];
    }

    #[DataProvider('unsafeLinks')]
    public function test_rejects_links_that_are_not_a_path_or_web_address(string $url): void
    {
        $this->post('/alex.morgan/actions', ['name' => 'Bad', 'type' => 'link', 'url' => $url])
            ->assertSessionHasErrors(['url' => 'Enter a path that starts with / (like /ticket) or a web address starting with https://.']);
    }

    public function test_updates_and_deletes_an_action(): void
    {
        $action = Action::factory()->for($this->user)->create(['name' => 'Old name']);

        $this->put("/alex.morgan/actions/{$action->id}", [
            'name' => 'New name',
            'type' => 'link',
            'url' => 'https://example.com/status',
            'show_on_home' => '0',
        ])->assertRedirect('/alex.morgan/actions');

        $action->refresh();
        $this->assertSame('New name', $action->name);
        $this->assertFalse($action->show_on_home);

        $this->delete("/alex.morgan/actions/{$action->id}")->assertRedirect('/alex.morgan/actions');
        $this->assertModelMissing($action);
    }

    public function test_running_a_link_action_redirects_and_records_the_run(): void
    {
        $action = Action::factory()->for($this->user)->create(['url' => '/ticket?assignee=me']);

        $this->post("/alex.morgan/actions/{$action->id}/run")->assertRedirect('/ticket?assignee=me');

        $this->assertNotNull($action->fresh()->last_run_at);
    }

    public function test_running_a_ticket_action_opens_the_prefilled_ticket_form(): void
    {
        $action = Action::factory()->for($this->user)->ticketTemplate()->create();

        $this->post("/alex.morgan/actions/{$action->id}/run")
            ->assertRedirect(route('tickets.create', [
                'subject' => 'Badge reader fault',
                'category' => 'facilities',
                'priority' => 'high',
                'description' => 'Which reader, and what happens when you badge?',
            ]));
    }

    public function test_returns_404_for_another_users_action_under_your_username(): void
    {
        $theirs = Action::factory()->create();

        $this->get("/alex.morgan/actions/{$theirs->id}/edit")->assertNotFound();
        $this->post("/alex.morgan/actions/{$theirs->id}/run")->assertNotFound();
        $this->delete("/alex.morgan/actions/{$theirs->id}")->assertNotFound();

        $this->assertModelExists($theirs);
    }

    public function test_forbids_another_persons_actions_page(): void
    {
        $other = User::factory()->create(['username' => 'derek.lamar']);
        $theirs = Action::factory()->for($other)->create();

        $this->get('/derek.lamar/actions')->assertForbidden();
        $this->post('/derek.lamar/actions', ['name' => 'X', 'type' => 'link', 'url' => '/'])->assertForbidden();
        $this->delete("/derek.lamar/actions/{$theirs->id}")->assertForbidden();

        $this->assertModelExists($theirs);
        $this->assertSame(1, Action::count());
    }

    public function test_escapes_action_names_on_the_actions_and_home_pages(): void
    {
        Action::factory()->for($this->user)->create(['name' => '<script>alert("x")</script>']);

        foreach (['/alex.morgan/actions', '/'] as $page) {
            $this->get($page)
                ->assertSee('&lt;script&gt;', false)
                ->assertDontSee('<script>alert("x")</script>', false);
        }
    }
}
