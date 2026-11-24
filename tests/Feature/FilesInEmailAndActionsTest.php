<?php

namespace Tests\Feature;

use App\Http\Controllers\EmailController;
use App\Mail\FortValeEmail;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\Department;
use App\Models\EmailTemplate;
use App\Models\Folder;
use App\Models\StoredFile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Files\FileStore;
use App\Services\Workflows\WorkflowScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FilesInEmailAndActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    private string $actionsFolder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('files');
        Mail::fake();
        $this->withoutDefer();
        $this->actionsFolder = storage_path('framework/testing/actions-'.uniqid());
        File::ensureDirectoryExists($this->actionsFolder);
        config(['fortvale.actions.path' => $this->actionsFolder]);

        $this->alex = User::factory()->withPermissions('tickets.assign', 'emails.view', 'emails.send', 'email_templates.view', 'email_templates.edit')
            ->create(['name' => 'Alex Morgan', 'username' => 'alex.morgan', 'email' => 'alex@fortvale.test']);
        $this->actingAs($this->alex);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->actionsFolder);

        parent::tearDown();
    }

    private function store(Folder $folder, string $name, string $content = 'content', ?User $by = null): StoredFile
    {
        return app(FileStore::class)->upload($by ?? $this->alex, $folder, UploadedFile::fake()->createWithContent($name, $content))['file'];
    }

    /**
     * @return list<string>
     */
    private static function names(FortValeEmail $mail): array
    {
        return array_map(fn ($attachment) => $attachment->as, $mail->attachments());
    }

    // Email page

    public function test_the_email_page_sends_files_from_the_library_new_uploads_and_the_templates_files(): void
    {
        $checklist = $this->store(Folder::factory()->create(['name' => 'Quality']), 'checklist.pdf', '%PDF-1.4 checklist');
        $template = EmailTemplate::create(['name' => 'Audit', 'subject' => 'Audit', 'body' => 'See attached.']);
        app(FileStore::class)->attach($this->alex, 'email_template', (string) $template->id, UploadedFile::fake()->createWithContent('audit-guide.txt', 'guide'));

        $this->get(route('emails.create', ['template' => $template->id]))
            ->assertSee('checklist.pdf')
            ->assertSee('The “Audit” template also sends: audit-guide.txt.');

        $this->post(route('emails.store'), [
            'addresses' => 'auditor@example.com', 'subject' => 'Audit', 'body' => 'See attached.',
            'template_id' => $template->id,
            'stored_files' => [$checklist->id],
            'uploads' => [UploadedFile::fake()->createWithContent('photo.png', 'png-ish')],
        ])->assertSessionHasNoErrors();

        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => self::names($mail) === ['checklist.pdf', 'photo.png', 'audit-guide.txt']);
        $this->assertSame(0, StoredFile::where('name', 'photo.png')->count(), 'Uploads on the email page are sent, not stored.');
    }

    public function test_the_file_picker_searches_every_file_you_may_attach(): void
    {
        $quality = Folder::factory()->create(['name' => 'Quality']);
        $old = $this->store($quality, 'Annual audit 2019.pdf', '%PDF-1.4');
        $old->forceFill(['updated_at' => now()->subYears(5)])->save();
        foreach (range(1, EmailController::RECENT_FILES) as $i) {
            $this->store($quality, "recent-{$i}.txt");
        }
        $hidden = Folder::factory()->create(['name' => 'HR']);
        $hidden->access()->create(['department_id' => Department::factory()->create()->id, 'abilities' => ['view']]);
        $this->store($hidden, 'Annual salaries.xlsx', 'x');
        $this->store(Folder::personalRootFor($this->alex), 'annual leave.txt');

        $this->get(route('emails.create'))->assertSee('Recently changed:')->assertDontSee('Annual audit 2019.pdf');

        $this->getJson(route('emails.files', ['q' => 'ANNUAL']))
            ->assertOk()
            ->assertJsonFragment(['id' => $old->id, 'name' => 'Annual audit 2019.pdf', 'path' => 'Library / Quality'])
            ->assertJsonFragment(['name' => 'annual leave.txt', 'path' => 'My files'])
            ->assertJsonMissing(['name' => 'Annual salaries.xlsx'])
            ->assertJsonCount(2);

        $this->actingAs(User::factory()->create())->getJson(route('emails.files', ['q' => 'annual']))->assertForbidden();
    }

    public function test_ticked_files_stay_ticked_after_a_form_error(): void
    {
        $old = $this->store(Folder::factory()->create(), 'old report.pdf', '%PDF-1.4');
        $old->forceFill(['updated_at' => now()->subYears(5)])->save();
        foreach (range(1, EmailController::RECENT_FILES) as $i) {
            $this->store(Folder::personalRootFor($this->alex), "n{$i}.txt");
        }

        $this->from(route('emails.create'))->post(route('emails.store'), ['subject' => 'x', 'body' => 'x', 'stored_files' => [$old->id]])
            ->assertSessionHasErrors('people');

        $this->get(route('emails.create'))->assertSee('old report.pdf')->assertSee('value="'.$old->id.'" checked', false);
    }

    public function test_files_you_cannot_read_and_oversized_attachments_are_refused(): void
    {
        $private = $this->store(Folder::personalRootFor($someone = User::factory()->create()), 'private.txt', 'x', $someone);

        $this->post(route('emails.store'), ['addresses' => 'x@example.com', 'subject' => 'x', 'body' => 'x', 'stored_files' => [$private->id]])
            ->assertSessionHasErrors('stored_files');

        $this->post(route('emails.store'), ['addresses' => 'x@example.com', 'subject' => 'x', 'body' => 'x', 'uploads' => [UploadedFile::fake()->create('huge.bin', 11 * 1024)]])
            ->assertSessionHasErrors('attachments');

        Mail::assertNothingSent();
    }

    // Templates

    public function test_a_templates_files_go_with_its_test_send_and_ticket_emails(): void
    {
        $assigned = EmailTemplate::where('key', 'ticket.assigned')->sole();
        app(FileStore::class)->attach($this->alex, 'email_template', (string) $assigned->id, UploadedFile::fake()->createWithContent('how-we-work.txt', 'rules'));

        $this->post(route('email-templates.test', $assigned));
        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => $mail->hasTo('alex@fortvale.test') && self::names($mail) === ['how-we-work.txt']);

        $sam = User::factory()->create(['email' => 'sam@fortvale.test']);
        $ticket = Ticket::factory()->create(['requester_id' => $this->alex->id]);
        $this->patch(route('tickets.update', $ticket), ['assignee_id' => $sam->id]);
        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => $mail->hasTo('sam@fortvale.test') && self::names($mail) === ['how-we-work.txt']);
    }

    // email/send with attachments

    public function test_the_email_step_attaches_files_its_owner_can_read(): void
    {
        $this->store(Folder::factory()->create(['name' => 'Quality']), 'checklist.pdf', '%PDF-1.4');
        $this->store(Folder::personalRootFor($this->alex), 'notes.txt');
        $action = Action::factory()->for($this->alex)->yaml(<<<'YAML'
            name: Send the checklist
            on: { run: }
            steps:
              - uses: email/send
                with:
                  to: x@example.com
                  subject: Checklist
                  body: Attached.
                  attachments: ["Library/Quality/checklist.pdf", "My files/notes.txt"]
            YAML)->create();

        $this->post(route('actions.custom.run', [$this->alex, $action]))->assertSessionHasNoErrors();

        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => self::names($mail) === ['checklist.pdf', 'notes.txt']);
        $this->assertSame(ActionRun::SUCCESS, ActionRun::sole()->status);
    }

    public function test_the_email_step_cannot_attach_files_its_owner_cannot_read(): void
    {
        $restricted = Folder::factory()->create(['name' => 'HR']);
        $restricted->access()->create(['department_id' => Department::factory()->create()->id, 'abilities' => ['view']]);
        $this->store($restricted, 'salaries.xlsx', 'x');

        $action = Action::factory()->for($this->alex)->yaml("name: Leak\non: { run: }\nsteps:\n  - uses: email/send\n    with: { to: x@example.com, subject: x, body: x, attachments: [\"Library/HR/salaries.xlsx\"] }")->create();
        $this->post(route('actions.custom.run', [$this->alex, $action]));

        Mail::assertNothingSent();
        $this->assertSame(ActionRun::FAILED, ActionRun::sole()->status);
        $this->assertStringContainsString('may not read it', json_encode(ActionRun::sole()->steps));
    }

    public function test_a_scheduled_shared_action_can_only_attach_files_from_open_library_folders(): void
    {
        $this->store(Folder::factory()->create(['name' => 'Public']), 'menu.pdf', '%PDF-1.4');
        $hr = Folder::factory()->create(['name' => 'HR']);
        $hr->access()->create(['department_id' => Department::factory()->create()->id, 'abilities' => ['view']]);
        $this->store($hr, 'salaries.xlsx', 'x');

        File::put("{$this->actionsFolder}/menu.yml", "name: Menu\non:\n  schedule:\n    - cron: \"0 9 * * 1\"\nsteps:\n  - uses: email/send\n    with: { to: all@example.com, subject: Menu, body: Menu, attachments: [\"Library/Public/menu.pdf\"] }\n");
        File::put("{$this->actionsFolder}/salaries.yml", "name: Salaries\non:\n  schedule:\n    - cron: \"0 9 * * 1\"\nsteps:\n  - uses: email/send\n    with: { to: all@example.com, subject: x, body: x, attachments: [\"Library/HR/salaries.xlsx\"] }\n");

        app(WorkflowScheduler::class)->runDue(now()->next('Monday')->setTime(9, 0));

        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => self::names($mail) === ['menu.pdf']);
        Mail::assertSentCount(1);
    }

    // file/save

    public function test_file_save_writes_a_file_and_a_new_version_on_the_next_run(): void
    {
        Folder::factory()->create(['name' => 'Reports']);
        File::put("{$this->actionsFolder}/report.yml", <<<'YAML'
            name: Write report
            on:
              run:
                inputs:
                  week: { required: true }
            steps:
              - id: save
                uses: file/save
                with:
                  folder: Library/Reports
                  name: weekly.txt
                  content: "Report for week ${{ inputs.week }} by ${{ actor.name }}"
              - uses: message/show
                with: { text: "Saved ${{ steps.save.file }} (version ${{ steps.save.version }})" }
            YAML);

        $this->post(route('actions.shared.run', 'report'), ['inputs' => ['week' => '47']])->assertSessionHas('status', 'Saved Library / Reports / weekly.txt (version 1)');
        $this->post(route('actions.shared.run', 'report'), ['inputs' => ['week' => '48']])->assertSessionHas('status', 'Saved Library / Reports / weekly.txt (version 2)');

        $file = StoredFile::where('name', 'weekly.txt')->sole();
        $this->assertSame('Report for week 48 by Alex Morgan', Storage::disk('files')->get($file->currentVersion->path));
        $this->assertSame($this->alex->id, $file->currentVersion->uploaded_by);
    }

    public function test_file_save_needs_access_to_the_folder(): void
    {
        $locked = Folder::factory()->create(['name' => 'Locked']);
        $locked->access()->create(['department_id' => Department::factory()->create()->id, 'abilities' => ['view', 'upload']]);
        $mine = Action::factory()->for($this->alex)->yaml("name: Note\non: { run: }\nsteps:\n  - uses: file/save\n    with: { folder: My files, name: note.txt, content: hi }")->create();
        $blocked = Action::factory()->for($this->alex)->yaml("name: Sneak\non: { run: }\nsteps:\n  - uses: file/save\n    with: { folder: Library/Locked, name: x.txt, content: hi }")->create();

        $this->post(route('actions.custom.run', [$this->alex, $mine]))->assertSessionHasNoErrors();
        $this->assertSame(Folder::personalRootFor($this->alex)->id, StoredFile::where('name', 'note.txt')->sole()->folder_id);

        $this->post(route('actions.custom.run', [$this->alex, $blocked]));
        $this->assertSame(0, StoredFile::where('name', 'x.txt')->count());
        $this->assertSame(ActionRun::FAILED, ActionRun::latest('id')->first()->status);
    }

    public function test_the_file_steps_are_validated(): void
    {
        $this->post(route('actions.store', $this->alex), ['yaml' => "name: Bad\non: { run: }\nsteps:\n  - uses: file/save\n    with: { folder: Somewhere, name: x.txt, content: x }"])
            ->assertSessionHasErrors(['yaml' => 'steps[0].with.folder: must start with "Library" or "My files", e.g. Library/Reports.']);
        $this->post(route('actions.store', $this->alex), ['yaml' => "name: Bad\non: { run: }\nsteps:\n  - uses: email/send\n    with: { to: x@example.com, subject: x, body: x, attachments: Library/x.pdf }"])
            ->assertSessionHasErrors('yaml');
    }
}
