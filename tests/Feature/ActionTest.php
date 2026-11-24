<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const LINK_ACTION = <<<'YAML'
        name: Urgent queue
        description: All urgent tickets
        on:
          run:
            show_on_home: true
        steps:
          - uses: page/open
            with:
              url: /ticket?priority=urgent
        YAML;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fortvale.actions.path' => storage_path('framework/testing/no-shared-actions')]);
        $this->user = User::factory()->create(['username' => 'alex.morgan']);
        $this->actingAs($this->user);
    }

    public function test_lists_only_the_current_users_actions(): void
    {
        Action::factory()->for($this->user)->yaml(str_replace('Urgent queue', 'My queue', self::LINK_ACTION))->create();
        Action::factory()->yaml(str_replace('Urgent queue', 'Not mine', self::LINK_ACTION))->create();

        $this->get('/alex.morgan/actions')
            ->assertOk()
            ->assertSee('My queue')
            ->assertDontSee('Not mine');
    }

    public function test_shows_an_empty_state_and_offers_templates(): void
    {
        $this->get('/alex.morgan/actions')->assertSee('You haven’t made any actions yet.');

        $this->get('/alex.morgan/actions/create?template=webhook')
            ->assertOk()
            ->assertSee('uses: http/request')
            ->assertSee('React to ticket events');
    }

    public function test_creates_an_action_from_yaml_and_caches_its_name_and_triggers(): void
    {
        $this->post('/alex.morgan/actions', ['yaml' => self::LINK_ACTION])
            ->assertRedirect('/alex.morgan/actions')
            ->assertSessionHas('status', '“Urgent queue” created.');

        $action = $this->user->actions()->sole();
        $this->assertSame('Urgent queue', $action->name);
        $this->assertSame(['run'], $action->triggers);
        $this->assertTrue($action->show_on_home);
        $this->assertSame(self::LINK_ACTION, $action->yaml);
    }

    public function test_rejects_invalid_yaml_listing_each_mistake_and_keeping_the_text(): void
    {
        $yaml = "name: Broken\non: { run: }\nsteps:\n  - uses: page/open\n    with: { url: 'javascript:alert(1)' }\n  - uses: shell/run\n";

        $this->from('/alex.morgan/actions/create')
            ->post('/alex.morgan/actions', ['yaml' => $yaml])
            ->assertRedirect('/alex.morgan/actions/create')
            ->assertSessionHasErrors('yaml')
            ->assertSessionHasInput('yaml', rtrim($yaml));

        $errors = session('errors')->get('yaml');
        $this->assertCount(3, $errors);
        $this->assertStringStartsWith('steps[0].with.url:', $errors[0]);
        $this->assertSame(0, Action::count());

        $this->post('/alex.morgan/actions', ['yaml' => ''])->assertSessionHasErrors(['yaml' => 'The yaml field is required.']);
    }

    public function test_updates_and_deletes_an_action(): void
    {
        $action = Action::factory()->for($this->user)->yaml(self::LINK_ACTION)->create();

        $this->get("/alex.morgan/actions/{$action->id}/edit")->assertOk()->assertSee('url: /ticket?priority=urgent');

        $this->put("/alex.morgan/actions/{$action->id}", ['yaml' => "name: Renamed\non:\n  ticket:\n    types: [created]\nsteps:\n  - uses: ticket/reply\n    with: { body: hi }\n"])
            ->assertRedirect('/alex.morgan/actions');

        $action->refresh();
        $this->assertSame('Renamed', $action->name);
        $this->assertSame(['ticket'], $action->triggers);
        $this->assertFalse($action->show_on_home);

        $this->delete("/alex.morgan/actions/{$action->id}")->assertRedirect('/alex.morgan/actions');
        $this->assertModelMissing($action);
    }

    public function test_clicking_a_personal_action_opens_its_yaml_editor(): void
    {
        $action = Action::factory()->for($this->user)->yaml(self::LINK_ACTION)->create();

        $this->get('/alex.morgan/actions')->assertSee('href="'.route('actions.edit', [$this->user, $action]).'" class="action-card-link"', false);
        $this->get('/')->assertSee('href="'.route('actions.edit', [$this->user, $action]).'"', false);
    }

    public function test_running_an_action_follows_its_last_step_and_records_the_run(): void
    {
        $action = Action::factory()->for($this->user)->yaml(self::LINK_ACTION)->create();

        $this->post("/alex.morgan/actions/{$action->id}/run")->assertRedirect('/ticket?priority=urgent');

        $this->assertNotNull($action->fresh()->last_run_at);
        $run = ActionRun::sole();
        $this->assertSame(["personal:{$action->id}", $action->id, $this->user->id, ActionRun::SUCCESS], [$run->action_key, $run->action_id, $run->owner_id, $run->status]);
        $this->get('/alex.morgan/actions')->assertSee('Succeeded')->assertSee('Urgent queue');
    }

    public function test_running_an_action_with_inputs_opens_the_prefilled_ticket_form(): void
    {
        $action = Action::factory()->for($this->user)->yaml(<<<'YAML'
            name: Camera fault
            on:
              run:
                inputs:
                  camera: { description: Camera number, required: true }
            steps:
              - uses: ticket/open-form
                with: { subject: "Camera ${{ inputs.camera }} fault", category: cctv, priority: high }
            YAML)->create();

        $this->get("/alex.morgan/actions/{$action->id}/run")->assertOk()->assertSee('Camera number');
        $this->post("/alex.morgan/actions/{$action->id}/run", ['inputs' => ['camera' => '']])->assertSessionHasErrors('inputs.camera');

        $this->post("/alex.morgan/actions/{$action->id}/run", ['inputs' => ['camera' => '14']])
            ->assertRedirect(route('tickets.create', ['subject' => 'Camera 14 fault', 'category' => 'cctv', 'priority' => 'high']));
    }

    public function test_actions_without_a_run_trigger_cannot_be_run_by_hand(): void
    {
        $action = Action::factory()->for($this->user)->yaml("name: Events\non: { ticket: { types: [created] } }\nsteps:\n  - uses: ticket/reply\n    with: { body: hi }\n")->create();

        $this->post("/alex.morgan/actions/{$action->id}/run")->assertNotFound();
        $this->get('/alex.morgan/actions')->assertDontSee(route('actions.custom.run', [$this->user, $action]), false);
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
        $this->post('/derek.lamar/actions', ['yaml' => self::LINK_ACTION])->assertForbidden();
        $this->post("/derek.lamar/actions/{$theirs->id}/run")->assertForbidden();
        $this->delete("/derek.lamar/actions/{$theirs->id}")->assertForbidden();

        $this->assertModelExists($theirs);
        $this->assertSame(1, Action::count());
    }

    public function test_escapes_action_names_on_the_actions_and_home_pages(): void
    {
        Action::factory()->for($this->user)->yaml(str_replace('Urgent queue', '"<script>alert(1)</script>"', self::LINK_ACTION))->create();

        foreach (['/alex.morgan/actions', '/'] as $page) {
            $this->get($page)
                ->assertSee('&lt;script&gt;', false)
                ->assertDontSee('<script>alert(1)</script>', false);
        }
    }
}
