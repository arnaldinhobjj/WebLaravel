<?php

namespace Tests\Feature;

use App\Models\ActionRun;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SharedActionTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alex = User::factory()->withPermissions('shared_actions.edit')->create(['name' => 'Alex Morgan', 'username' => 'alex.morgan']);
        $this->actingAs($this->alex);

        $this->folder = storage_path('framework/testing/actions-'.uniqid());
        File::ensureDirectoryExists($this->folder);
        config(['fortvale.actions.path' => $this->folder]);

        $this->writeAction('hello', "name: Say hello\ndescription: Greets you\non:\n  run:\n    show_on_home: true\nsteps:\n  - uses: message/show\n    with: { text: 'Hello \${{ actor.name }}' }\n");
        $this->writeAction('room', <<<'YAML'
            name: Book a room
            on:
              run:
                inputs:
                  room: { description: Room, required: true }
            steps:
              - uses: ticket/create
                with: { subject: "Book room ${{ inputs.room }}", category: facilities }
              - uses: message/show
                with: { text: "Requested ${{ inputs.room }}" }
            YAML);
        $this->writeAction('broken', "name: Broken\non: { run: }\nsteps:\n  - uses: shell/run\n");
        $this->writeAction('events-only', "name: Events only\non: { ticket: { types: [created] } }\nsteps:\n  - uses: ticket/reply\n    with: { body: hi }\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    private function writeAction(string $slug, string $yaml): void
    {
        File::put("{$this->folder}/{$slug}.yml", $yaml);
    }

    public function test_actions_page_lists_shared_actions_and_explains_broken_files(): void
    {
        $this->get('/alex.morgan/actions')
            ->assertOk()
            ->assertSeeInOrder(['Shared actions', 'actions/broken.yml', 'steps[0].uses: must be one of', 'Events only', 'Say hello', 'Book a room', 'My actions'])
            ->assertSee(route('actions.shared.run.form', 'room'), false)
            ->assertSee(route('actions.shared.run', 'hello'), false);
    }

    public function test_clicking_a_shared_action_opens_its_yaml_in_an_editor(): void
    {
        $this->get('/alex.morgan/actions')->assertSee('href="'.route('actions.shared.show', 'hello').'" class="action-card-link"', false);
        $this->get('/')->assertSee('href="'.route('actions.shared.show', 'hello').'"', false);

        $this->get('/actions/shared/hello')
            ->assertOk()
            ->assertSee('actions/hello.yml')
            ->assertSee('<textarea id="yaml" name="yaml"', false)
            ->assertSee('Hello ${{ actor.name }}')
            ->assertSee('name="hash" value="'.sha1_file("{$this->folder}/hello.yml").'"', false);
    }

    public function test_saving_valid_yaml_writes_the_shared_file(): void
    {
        $hash = sha1_file("{$this->folder}/hello.yml");
        $yaml = "# Greeting\nname: Say hi\non:\n  run:\n    show_on_home: true\nsteps:\n  - uses: message/show\n    with: { text: Hi }";

        $this->put('/actions/shared/hello', ['yaml' => $yaml, 'hash' => $hash])
            ->assertRedirect('/actions/shared/hello')
            ->assertSessionHas('status', '“Say hi” saved to actions/hello.yml.');

        $this->assertSame($yaml."\n", file_get_contents("{$this->folder}/hello.yml"));
        $this->from('/')->post('/actions/shared/hello/run')->assertSessionHas('status', 'Hi');
    }

    public function test_invalid_yaml_is_not_saved_and_the_mistakes_are_listed(): void
    {
        $before = file_get_contents("{$this->folder}/hello.yml");

        $this->from('/actions/shared/hello')
            ->put('/actions/shared/hello', ['yaml' => "name: Bad\non: { run: }\nsteps:\n  - uses: shell/run", 'hash' => sha1($before)])
            ->assertRedirect('/actions/shared/hello')
            ->assertSessionHasErrors('yaml');

        $this->assertSame($before, file_get_contents("{$this->folder}/hello.yml"));
    }

    public function test_refuses_to_overwrite_a_file_changed_since_the_editor_was_opened(): void
    {
        $openedHash = sha1_file("{$this->folder}/hello.yml");
        $this->writeAction('hello', "name: Changed elsewhere\non: { run: }\nsteps:\n  - uses: message/show\n    with: { text: x }\n");

        $this->put('/actions/shared/hello', ['yaml' => "name: Mine\non: { run: }\nsteps:\n  - uses: message/show\n    with: { text: y }", 'hash' => $openedHash])
            ->assertSessionHasErrors(['yaml' => 'This file was changed by someone else since you opened it. Copy your changes, reload the page and try again.']);

        $this->assertStringContainsString('Changed elsewhere', file_get_contents("{$this->folder}/hello.yml"));
    }

    public function test_a_broken_shared_file_can_be_opened_and_fixed(): void
    {
        $this->get('/actions/shared/broken')
            ->assertOk()
            ->assertSee('This file has mistakes')
            ->assertSee('steps[0].uses: must be one of');

        $this->put('/actions/shared/broken', [
            'yaml' => "name: Fixed\non: { run: }\nsteps:\n  - uses: message/show\n    with: { text: fixed }",
            'hash' => sha1_file("{$this->folder}/broken.yml"),
        ])->assertSessionHasNoErrors();

        $this->from('/')->post('/actions/shared/broken/run')->assertSessionHas('status', 'fixed');
    }

    public function test_people_without_the_permission_can_read_but_not_save_shared_actions(): void
    {
        $before = file_get_contents("{$this->folder}/hello.yml");
        $this->actingAs(User::factory()->create());

        $this->get('/actions/shared/hello')
            ->assertOk()
            ->assertSee('Hello ${{ actor.name }}')
            ->assertSee('readonly', false)
            ->assertDontSee('Save file');

        $this->put('/actions/shared/hello', ['yaml' => "name: Mine\non: { run: }\nsteps:\n  - uses: message/show\n    with: { text: y }", 'hash' => sha1($before)])
            ->assertForbidden();

        $this->assertSame($before, file_get_contents("{$this->folder}/hello.yml"));
        $this->from('/')->post('/actions/shared/hello/run')->assertRedirect('/');
    }

    public function test_editing_unknown_or_unsafe_names_returns_404(): void
    {
        $this->put('/actions/shared/missing', ['yaml' => 'name: x', 'hash' => 'x'])->assertNotFound();
        $this->put('/actions/shared/..%2F.env', ['yaml' => 'name: x', 'hash' => 'x'])->assertNotFound();
        $this->assertFileDoesNotExist("{$this->folder}/missing.yml");
    }

    public function test_runs_a_shared_action_and_records_the_run(): void
    {
        $this->from('/')->post('/actions/shared/hello/run')
            ->assertRedirect('/')
            ->assertSessionHas('status', 'Hello Alex Morgan');

        $run = ActionRun::sole();
        $this->assertSame(['shared:hello', 'Say hello', 'run', ActionRun::SUCCESS, $this->alex->id, null], [$run->action_key, $run->action_name, $run->trigger, $run->status, $run->user_id, $run->owner_id]);
    }

    public function test_actions_with_inputs_ask_for_them_first(): void
    {
        $this->get('/actions/shared/room/run')->assertOk()->assertSee('name="inputs[room]"', false);

        $this->post('/actions/shared/room/run', [])->assertSessionHasErrors('inputs.room');
        $this->assertSame(0, ActionRun::count());

        $this->post('/actions/shared/room/run', ['inputs' => ['room' => '2B']])
            ->assertRedirect('/alex.morgan/actions')
            ->assertSessionHas('status', 'Requested 2B');

        $this->assertSame('facilities', Ticket::where('subject', 'Book room 2B')->sole()->category);
    }

    public function test_home_screen_lists_run_button_actions_shown_on_home(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Say hello')
            ->assertDontSee('Book a room')
            ->assertDontSee('Events only');
    }

    public function test_returns_404_for_unknown_broken_event_only_or_unsafe_names(): void
    {
        $this->post('/actions/shared/missing/run')->assertNotFound();
        $this->post('/actions/shared/broken/run')->assertNotFound();
        $this->post('/actions/shared/events-only/run')->assertNotFound();
        $this->get('/actions/shared/..%2F.env')->assertNotFound();
        $this->get('/actions/shared/Hello')->assertNotFound();

        $this->assertSame(0, ActionRun::count());
    }
}
