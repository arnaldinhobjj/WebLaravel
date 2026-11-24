<?php

namespace Tests\Feature;

use App\Models\ActionRun;
use App\Models\Department;
use App\Models\EmailTemplate;
use App\Models\Folder;
use App\Models\StoredFile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Files\FileStore;
use App\Services\Workflows\HostResolver;
use App\Services\Workflows\InvalidWorkflow;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowParser;
use App\Services\Workflows\WorkflowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fixes for the findings of the code review (personal actions, deep
 * folders, private addresses, trash, renames, templates, ticket editing).
 */
class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private function runPersonal(User $owner, string $yaml, ?Ticket $ticket = null, ?User $actor = null): ActionRun
    {
        $workflow = app(WorkflowParser::class)->parse($yaml, Workflow::PERSONAL, 'personal:test', $owner->id);

        return app(WorkflowRunner::class)->run($workflow, $ticket ? 'ticket' : 'run', $actor ?? $owner, [], $ticket, $ticket ? 'created' : null)->run;
    }

    public function test_personal_actions_cannot_touch_tickets_their_owner_cannot_see(): void
    {
        $owner = User::factory()->withPermissions('tickets.edit')->create();
        $hidden = Ticket::factory()->create(['subject' => 'Not yours']);

        $run = $this->runPersonal($owner, "name: Sneak\non: { run: }\nsteps:\n  - uses: ticket/update\n    with: { ticket: {$hidden->id}, status: closed }");
        $this->assertFalse($run->succeeded());
        $this->assertStringContainsString("isn't one", $run->steps[0]['output']);
        $this->assertSame('open', $hidden->fresh()->status->value);

        $run = $this->runPersonal($owner, "name: Sneak\non: { run: }\nsteps:\n  - uses: ticket/reply\n    with: { ticket: {$hidden->id}, body: hi }");
        $this->assertFalse($run->succeeded());
        $this->assertSame(0, $hidden->comments()->count());
    }

    public function test_personal_actions_need_their_owners_permissions_and_act_as_the_owner(): void
    {
        $owner = User::factory()->create(['name' => 'Owner']);
        $mine = Ticket::factory()->create(['requester_id' => $owner->id]);

        $run = $this->runPersonal($owner, "name: Close\non: { run: }\nsteps:\n  - uses: ticket/update\n    with: { ticket: {$mine->id}, status: closed }");
        $this->assertFalse($run->succeeded(), 'the owner may not change tickets (no tickets.edit)');
        $this->assertStringContainsString('tickets.edit', $run->steps[0]['output']);

        $run = $this->runPersonal($owner, "name: Reply\non: { run: }\nsteps:\n  - uses: ticket/reply\n    with: { ticket: {$mine->id}, body: Thanks }");
        $this->assertTrue($run->succeeded(), json_encode($run->steps));

        // Triggered by someone else's change, it still acts as its owner.
        $sam = User::factory()->withPermissions('tickets.view_all')->create();
        $run = $this->runPersonal($owner, "name: Ack\non: { ticket: { types: [created] } }\nsteps:\n  - uses: ticket/reply\n    with: { body: Seen }", $mine, $sam);
        $this->assertTrue($run->succeeded(), json_encode($run->steps));
        $this->assertSame($owner->id, $mine->comments()->latest('id')->first()->user_id);
    }

    public function test_personal_actions_may_not_use_as(): void
    {
        $this->expectException(InvalidWorkflow::class);
        $this->expectExceptionMessage('personal actions always act as their owner');

        app(WorkflowParser::class)->parse("name: X\non: { run: }\nsteps:\n  - uses: ticket/reply\n    with: { ticket: 1, body: x, as: someone }", Workflow::PERSONAL, 'personal:x');
    }

    public function test_shared_actions_cannot_use_someone_elses_my_files(): void
    {
        Storage::fake('files');
        $folder = storage_path('framework/testing/actions-'.uniqid());
        File::ensureDirectoryExists($folder);
        config(['fortvale.actions.path' => $folder]);
        File::put("{$folder}/note.yml", "name: Note\non: { run: }\nsteps:\n  - uses: file/save\n    with: { folder: My files, name: note.txt, content: hi, as: sam.okafor }\n");

        $sam = User::factory()->create(['username' => 'sam.okafor']);
        $alex = User::factory()->withPermissions('shared_actions.edit')->create();

        $this->actingAs($alex)->post(route('actions.shared.run', 'note'));
        $this->assertSame(0, StoredFile::count());
        $this->assertStringContainsString('My files of the person running them', json_encode(ActionRun::latest('id')->first()->steps));

        $this->actingAs($sam)->post(route('actions.shared.run', 'note'));
        $this->assertSame(Folder::personalRootFor($sam)->id, StoredFile::sole()->folder_id);

        File::deleteDirectory($folder);
    }

    public function test_folder_rules_still_apply_very_deep_down(): void
    {
        Storage::fake('files');
        $hr = Folder::factory()->create(['name' => 'HR']);
        $hr->access()->create(['department_id' => Department::factory()->create()->id, 'abilities' => ['view']]);
        $deep = $hr;
        foreach (range(1, 60) as $level) {
            $deep = Folder::create(['parent_id' => $deep->id, 'name' => "L{$level}"]);
        }

        $this->assertSame($hr->id, $deep->topLevel()->id);
        $this->actingAs(User::factory()->create())->get(route('files.folder', $deep))->assertNotFound();
    }

    public function test_more_private_address_ranges_are_refused(): void
    {
        foreach (['100.64.1.1', '64:ff9b::7f00:1', '::ffff:127.0.0.1', '2002:7f00:1::', '198.18.0.5', '169.254.169.254', '10.0.0.1', 'fd00::1'] as $address) {
            $this->assertFalse(HostResolver::isPublicAddress($address), "{$address} is not public");
        }
        foreach (['8.8.8.8', '2606:4700::1111', '64:ff9b::808:808'] as $address) {
            $this->assertTrue(HostResolver::isPublicAddress($address), "{$address} is public");
        }

        $pinned = HostResolver::pinnedTo('https://example.com:8443/x', '93.184.215.14');
        $this->assertSame(['example.com:8443:93.184.215.14'], $pinned['curl'][CURLOPT_RESOLVE]);
    }

    public function test_empty_trash_only_removes_what_you_can_see(): void
    {
        Storage::fake('files');
        $store = app(FileStore::class);
        $admin = User::factory()->withPermissions('files.delete', 'files.empty_trash')->create();
        $open = Folder::factory()->create(['name' => 'Open']);
        $hr = Folder::factory()->create(['name' => 'HR']);
        $hr->access()->create(['department_id' => Department::factory()->create()->id, 'abilities' => ['view', 'delete']]);
        $visible = $store->upload($admin, $open, UploadedFile::fake()->createWithContent('old.txt', 'x'))['file'];
        $hidden = $store->upload($admin, $hr, UploadedFile::fake()->createWithContent('payroll.txt', 'x'))['file'];
        $visible->delete();
        $hidden->delete();

        $this->actingAs($admin)->post(route('files.trash.empty'), ['area' => 'library'])->assertSessionHas('status');

        $this->assertNull(StoredFile::withTrashed()->find($visible->id));
        $this->assertNotNull(StoredFile::withTrashed()->find($hidden->id), "HR's trash isn't the admin's to empty");
    }

    public function test_renaming_to_a_blocked_type_is_refused(): void
    {
        Storage::fake('files');
        $me = User::factory()->create();
        $file = app(FileStore::class)->upload($me, Folder::personalRootFor($me), UploadedFile::fake()->createWithContent('notes.txt', 'x'))['file'];

        $this->actingAs($me)->patch(route('files.rename', $file), ['name' => 'notes.exe'])->assertSessionHasErrors('name');
        $this->assertSame('notes.txt', $file->fresh()->name);
    }

    public function test_sending_a_templates_files_needs_permission_to_see_templates(): void
    {
        Mail::fake();
        $template = EmailTemplate::create(['name' => 'Secret pack', 'subject' => 'x', 'body' => 'x']);
        $sender = User::factory()->withPermissions('emails.view', 'emails.send')->create();

        $this->actingAs($sender)->get(route('emails.create', ['template' => $template->id]))->assertDontSee('Secret pack');
        $this->post(route('emails.store'), ['addresses' => 'x@example.com', 'subject' => 'x', 'body' => 'x', 'template_id' => $template->id])->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_only_the_helpdesk_changes_and_reassigns_tickets(): void
    {
        $requester = User::factory()->create();
        $ticket = Ticket::factory()->create(['requester_id' => $requester->id]);

        $this->actingAs($requester);
        $this->get(route('tickets.show', $ticket))->assertOk()->assertDontSee('name="status"', false)->assertSee('name="body"', false);
        $this->patch(route('tickets.update', $ticket), ['status' => 'closed'])->assertForbidden();
        $this->post(route('tickets.comment', $ticket), ['body' => 'Any news?'])->assertRedirect();

        $helpdesk = User::factory()->withPermissions('tickets.view_all', 'tickets.edit', 'tickets.assign')->create();
        $this->actingAs($helpdesk)->patch(route('tickets.update', $ticket), ['status' => 'in_progress', 'assignee_id' => $helpdesk->id])->assertSessionHasNoErrors();
        $this->assertSame([$helpdesk->id, 'in_progress'], [$ticket->fresh()->assignee_id, $ticket->fresh()->status->value]);
    }
}
