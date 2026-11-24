<?php

namespace Tests\Feature;

use App\Mail\FortValeEmail;
use App\Models\EmailTemplate;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('files');
        Mail::fake();
        $this->withoutDefer();
        $this->alex = User::factory()->create(['name' => 'Alex Morgan', 'email' => 'alex@fortvale.test']);
        $this->sam = User::factory()->create(['name' => 'Sam Okafor', 'email' => 'sam@fortvale.test']);
        $this->actingAs($this->alex);
    }

    private function file(string $name, string $content = 'content'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_files_attached_to_a_new_ticket_follow_the_tickets_visibility(): void
    {
        $this->post(route('tickets.store'), [
            'subject' => 'Printer jams', 'description' => 'Floor 2', 'category' => 'it', 'priority' => 'normal',
            'files' => [$this->file('error.txt', 'E42 paper jam')],
        ])->assertSessionHasNoErrors();

        $ticket = Ticket::where('subject', 'Printer jams')->sole();
        $attachment = StoredFile::where('attachable_type', 'ticket')->sole();
        $this->assertSame((string) $ticket->id, $attachment->attachable_id);
        $this->assertNull($attachment->folder_id);

        $this->get(route('tickets.show', $ticket))->assertSee('error.txt');
        $this->assertSame('E42 paper jam', $this->get(route('files.download', $attachment))->streamedContent());
        $this->get(route('files.preview', $attachment))->assertOk()->assertSee('E42 paper jam')->assertSee(route('tickets.show', $ticket));

        $this->actingAs($this->sam);
        $this->get(route('files.download', $attachment))->assertNotFound();
        $this->get(route('files.preview', $attachment))->assertNotFound();
        $this->post(route('attachments.store', ['ticket', $ticket->id]), ['files' => [$this->file('x.txt')]])->assertNotFound();
    }

    public function test_a_reply_can_carry_files_and_the_email_mentions_them(): void
    {
        // As in real use: ticket emails go out after the response, once the files are stored.
        $this->withDefer();
        $ticket = Ticket::factory()->create(['requester_id' => $this->sam->id, 'assignee_id' => $this->alex->id]);

        $this->post(route('tickets.comment', $ticket), ['body' => 'Photo of the cabinet.', 'files' => [$this->file('cabinet.jpg', 'jpeg-ish')]])
            ->assertSessionHasNoErrors();

        $attachment = StoredFile::where('attachable_type', 'ticket_reply')->sole();
        $reply = $ticket->comments()->sole();
        $this->assertSame((string) $reply->id, $attachment->attachable_id);
        $this->get(route('tickets.show', $ticket))->assertSeeInOrder(['Photo of the cabinet.', 'cabinet.jpg']);

        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => $mail->hasTo('sam@fortvale.test')
            && str_contains($mail->text, '1 attached file. Open the ticket to see it.'));

        $this->actingAs($this->sam)->get(route('files.download', $attachment))->assertOk();
    }

    public function test_limits_are_checked_before_the_ticket_or_reply_is_saved(): void
    {
        $this->post(route('tickets.store'), [
            'subject' => 'Bad', 'description' => 'x', 'category' => 'it', 'priority' => 'normal',
            'files' => [$this->file('tool.exe')],
        ])->assertSessionHasErrors('files');
        $this->assertSame(0, Ticket::where('subject', 'Bad')->count());

        $ticket = Ticket::factory()->create(['requester_id' => $this->alex->id]);
        $this->post(route('tickets.comment', $ticket), ['body' => 'With a program', 'files' => [$this->file('run.bat')]])->assertSessionHasErrors('files');
        $this->assertSame(0, $ticket->comments()->count());
        $this->assertSame(0, StoredFile::count());
    }

    public function test_the_attachments_box_needs_reply_permission_and_removal_follows_ownership(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->alex->id, 'assignee_id' => $this->sam->id]);

        $this->post(route('attachments.store', ['ticket', $ticket->id]), ['files' => [$this->file('log.txt')]])->assertSessionHas('status', '1 file attached.');
        $attachment = StoredFile::where('attachable_type', 'ticket')->sole();

        // Sam can see the ticket but didn't add the file; without "Tickets: Edit" he can't remove it.
        Tag::everyone()->update(['permissions' => ['tickets.view']]);
        $this->actingAs($this->sam);
        $this->get(route('tickets.show', $ticket))->assertSee('log.txt')->assertDontSee('Remove')->assertDontSee('to attach');
        $this->delete(route('files.destroy', $attachment))->assertForbidden();
        $this->post(route('attachments.store', ['ticket', $ticket->id]), ['files' => [$this->file('y.txt')]])->assertForbidden();

        // Attachments can't be renamed, moved or given versions.
        $this->actingAs($this->alex);
        $this->patch(route('files.rename', $attachment), ['name' => 'x.txt'])->assertForbidden();

        $this->delete(route('files.destroy', $attachment))->assertRedirect();
        $this->assertSoftDeleted($attachment);
    }

    public function test_email_templates_and_shared_actions_have_attachments_too(): void
    {
        $editor = User::factory()->withPermissions('email_templates.edit', 'shared_actions.edit')->create();
        $viewer = User::factory()->withPermissions('email_templates.view')->create();
        $template = EmailTemplate::create(['name' => 'Induction', 'subject' => 'Welcome', 'body' => 'Hi']);

        $folder = storage_path('framework/testing/actions-'.uniqid());
        File::ensureDirectoryExists($folder);
        File::put("{$folder}/hello.yml", "name: Hello\non: { run: }\nsteps:\n  - uses: message/show\n    with: { text: Hi }\n");
        config(['fortvale.actions.path' => $folder]);

        $this->actingAs($editor);
        $this->post(route('attachments.store', ['email_template', $template->id]), ['files' => [$this->file('handbook.pdf', '%PDF-1.4')]])->assertSessionHasNoErrors();
        $this->post(route('attachments.store', ['shared_action', 'hello']), ['files' => [$this->file('notes.txt')]])->assertSessionHasNoErrors();
        $this->get(route('email-templates.edit', $template))->assertSee('handbook.pdf')->assertSee('to attach');
        $this->get(route('actions.shared.show', 'hello'))->assertSee('notes.txt');

        $handbook = StoredFile::where('name', 'handbook.pdf')->sole();
        $this->actingAs($viewer);
        $this->get(route('files.download', $handbook))->assertOk();
        $this->post(route('attachments.store', ['email_template', $template->id]), ['files' => [$this->file('x.txt')]])->assertForbidden();

        $this->actingAs(User::factory()->create());
        $this->get(route('files.download', $handbook))->assertNotFound();
        $this->post(route('attachments.store', ['shared_action', 'missing']), ['files' => [$this->file('x.txt')]])->assertNotFound();
        $this->post('/attachments/user/1', ['files' => [$this->file('x.txt')]])->assertNotFound();

        File::deleteDirectory($folder);
    }
}
