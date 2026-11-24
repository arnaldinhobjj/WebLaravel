<?php

namespace Tests\Feature;

use App\Mail\FortValeEmail;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\AppSetting;
use App\Models\EmailTemplate;
use App\Models\SentEmail;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_EMAIL = [
        'emails.view', 'emails.send',
        'email_templates.view', 'email_templates.create', 'email_templates.edit', 'email_templates.delete',
        'email_settings.view', 'email_settings.edit',
    ];

    private User $alex;

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withoutDefer();
        config(['fortvale.actions.path' => storage_path('framework/testing/no-shared-actions')]);

        $this->alex = User::factory()->withPermissions(...self::ALL_EMAIL)->create(['name' => 'Alex Morgan', 'username' => 'alex.morgan', 'email' => 'alex@fortvale.test']);
        $this->sam = User::factory()->create(['name' => 'Sam Okafor', 'username' => 'sam.okafor', 'email' => 'sam@fortvale.test']);
        $this->actingAs($this->alex);
    }

    // Automatic ticket emails

    public function test_assigning_a_ticket_emails_the_new_assignee_with_the_template(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'Camera 9 offline', 'requester_id' => $this->alex->id]);

        $this->patch(route('tickets.update', $ticket), ['assignee_id' => $this->sam->id]);

        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => $mail->hasTo('sam@fortvale.test')
            && $mail->emailSubject === "Assigned to you: #{$ticket->id} Camera 9 offline"
            && str_contains($mail->text, 'Hi Sam Okafor,')
            && str_contains($mail->text, 'Alex Morgan assigned ticket')
            && $mail->link === route('tickets.show', $ticket));
        Mail::assertSentCount(1);
        $this->assertSame(['sam@fortvale.test'], SentEmail::sole()->to);
    }

    public function test_replies_email_the_requester_and_assignee_but_never_the_person_replying(): void
    {
        $derek = User::factory()->create(['email' => 'derek@fortvale.test']);
        $ticket = Ticket::factory()->create(['requester_id' => $derek->id, 'assignee_id' => $this->alex->id]);

        $this->post(route('tickets.comment', $ticket), ['body' => 'Switch replaced.']);

        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => $mail->hasTo('derek@fortvale.test') && str_contains($mail->text, 'Switch replaced.'));
        Mail::assertNotSent(FortValeEmail::class, fn (FortValeEmail $mail) => $mail->hasTo('alex@fortvale.test'));
    }

    public function test_people_can_turn_ticket_emails_off(): void
    {
        $this->sam->update(['email_preferences' => ['assigned' => false]]);
        $ticket = Ticket::factory()->create();

        $this->patch(route('tickets.update', $ticket), ['assignee_id' => $this->sam->id]);

        Mail::assertNothingSent();
    }

    public function test_the_settings_page_saves_email_choices(): void
    {
        $this->put(route('settings.update', $this->alex), [
            'name' => 'Alex Morgan', 'username' => 'alex.morgan', 'email' => 'alex@fortvale.test', 'theme' => 'system',
            'email_preferences_shown' => '1', 'email_preferences' => ['created', 'replied'],
        ])->assertSessionHasNoErrors();

        $alex = $this->alex->fresh();
        $this->assertTrue($alex->wantsEmail('replied'));
        $this->assertFalse($alex->wantsEmail('assigned'));
        $this->get(route('settings.edit', $alex))->assertSee('Email me when');
    }

    public function test_people_who_cannot_see_the_ticket_are_not_emailed(): void
    {
        Tag::everyone()->update(['permissions' => []]);
        $ticket = Ticket::factory()->create();

        $this->actingAs(User::factory()->withPermissions('tickets.view', 'tickets.view_all', 'tickets.assign')->create())
            ->patch(route('tickets.update', $ticket), ['assignee_id' => $this->sam->id]);

        Mail::assertNothingSent();
    }

    public function test_category_recipients_get_the_new_ticket_email(): void
    {
        AppSetting::put('mail.category_recipients', ['it' => ['it-support@example.com']]);

        $this->post(route('tickets.store'), ['subject' => 'Printer jams', 'description' => 'Floor 2', 'category' => 'it', 'priority' => 'normal']);
        $this->post(route('tickets.store'), ['subject' => 'Camera', 'description' => 'Gate', 'category' => 'cctv', 'priority' => 'normal']);

        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => $mail->hasTo('it-support@example.com') && str_contains($mail->emailSubject, 'Printer jams'));
        Mail::assertSentCount(1);
    }

    // Compose page

    public function test_composing_sends_one_copy_per_person_and_address(): void
    {
        $this->post(route('emails.store'), [
            'people' => [$this->sam->id],
            'addresses' => 'guest@example.com, sam@fortvale.test',
            'subject' => 'Fire drill',
            'body' => 'Hi ${{ recipient.name }}, drill at 3pm. — ${{ actor.name }}',
        ])->assertRedirect(route('emails.create'))->assertSessionHas('status', 'Email sent to 2 recipients.');

        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => $mail->hasTo('sam@fortvale.test') && $mail->text === 'Hi Sam Okafor, drill at 3pm. — Alex Morgan');
        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => $mail->hasTo('guest@example.com') && str_starts_with($mail->text, 'Hi guest@example.com,'));
        Mail::assertSentCount(2);
        $this->get(route('emails.create'))->assertSee('Fire drill');
    }

    public function test_composing_rejects_bad_addresses_too_many_and_nobody(): void
    {
        $this->post(route('emails.store'), ['addresses' => 'not-an-address', 'subject' => 'x', 'body' => 'x'])->assertSessionHasErrors('addresses');
        $this->post(route('emails.store'), ['subject' => 'x', 'body' => 'x'])->assertSessionHasErrors('people');

        $many = implode(',', array_map(fn (int $i) => "p{$i}@example.com", range(1, 21)));
        $this->post(route('emails.store'), ['addresses' => $many, 'subject' => 'x', 'body' => 'x'])->assertSessionHasErrors('addresses');

        Mail::assertNothingSent();
    }

    public function test_sending_needs_the_send_permission(): void
    {
        $this->actingAs($this->sam);

        $this->get(route('emails.create'))->assertForbidden();
        $this->post(route('emails.store'), ['addresses' => 'x@example.com', 'subject' => 'x', 'body' => 'x'])->assertForbidden();
        $this->get('/')->assertDontSee('href="'.route('emails.create').'"', false);

        Mail::assertNothingSent();
    }

    public function test_a_template_prefills_the_compose_page(): void
    {
        $template = EmailTemplate::create(['name' => 'Drill', 'subject' => 'Fire drill today', 'body' => 'Meet at the gate.']);

        $this->get(route('emails.create', ['template' => $template->id]))->assertSee('value="Fire drill today"', false)->assertSee('Meet at the gate.');
    }

    // Templates and settings

    public function test_templates_can_be_managed_but_built_in_ones_not_deleted(): void
    {
        $this->post(route('email-templates.store'), ['name' => 'Drill', 'subject' => 'Drill', 'body' => 'Gate'])->assertRedirect(route('email-templates.index'));
        $drill = EmailTemplate::where('name', 'Drill')->sole();

        $this->put(route('email-templates.update', $drill), ['name' => 'Drill', 'subject' => 'Fire drill', 'body' => 'Gate'])->assertSessionHasNoErrors();
        $this->assertSame('Fire drill', $drill->fresh()->subject);

        $builtIn = EmailTemplate::where('key', 'ticket.assigned')->sole();
        $this->delete(route('email-templates.destroy', $builtIn))->assertSessionHasErrors('name');
        $this->assertModelExists($builtIn);

        $this->delete(route('email-templates.destroy', $drill))->assertRedirect();
        $this->assertModelMissing($drill);

        $this->get(route('email-templates.index'))->assertSeeInOrder(['New ticket', 'Built in']);
    }

    public function test_send_a_test_of_a_template_to_yourself(): void
    {
        $template = EmailTemplate::where('key', 'ticket.replied')->sole();

        $this->post(route('email-templates.test', $template))->assertSessionHas('status', 'Test sent to alex@fortvale.test.');

        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => $mail->hasTo('alex@fortvale.test') && str_contains($mail->text, 'This is an example reply.'));
    }

    public function test_settings_set_the_sender_and_validate_category_recipients(): void
    {
        $this->put(route('email-settings.update'), ['from_name' => 'Fort Vale Desk', 'from_address' => 'desk@example.com', 'categories' => ['it' => 'nope']])
            ->assertSessionHasErrors('categories.it');

        $this->put(route('email-settings.update'), ['from_name' => 'Fort Vale Desk', 'from_address' => 'desk@example.com', 'categories' => ['it' => 'A@example.com; b@example.com']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['it' => ['a@example.com', 'b@example.com']], AppSetting::get('mail.category_recipients'));

        $this->post(route('emails.store'), ['addresses' => 'x@example.com', 'subject' => 'Hi', 'body' => 'Hi']);
        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => $mail->hasFrom('desk@example.com', 'Fort Vale Desk'));

        $this->actingAs($this->sam)->put(route('email-settings.update'), ['from_name' => 'x', 'from_address' => 'x@example.com'])->assertForbidden();
    }

    // Action step

    public function test_the_email_step_sends_with_inline_text_or_a_template(): void
    {
        EmailTemplate::create(['name' => 'Camera fault', 'subject' => 'Camera ${{ inputs.camera }} is down', 'body' => 'Hi ${{ recipient.name }}, see camera ${{ inputs.camera }}.']);
        $inline = Action::factory()->for($this->alex)->yaml(<<<'YAML'
            name: Tell IT
            on:
              run:
                inputs:
                  camera: { required: true }
            steps:
              - uses: email/send
                with:
                  to: [sam.okafor, it@example.com]
                  subject: "Camera ${{ inputs.camera }}"
                  body: "Hello ${{ recipient.name }}, camera ${{ inputs.camera }} from ${{ actor.name }}."
              - uses: email/send
                with:
                  to: sam.okafor
                  template: Camera fault
            YAML)->create();

        $this->post(route('actions.custom.run', [$this->alex, $inline]), ['inputs' => ['camera' => '14']])->assertSessionHasNoErrors();

        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => $mail->hasTo('sam@fortvale.test') && $mail->text === 'Hello Sam Okafor, camera 14 from Alex Morgan.');
        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => $mail->hasTo('it@example.com') && $mail->text === 'Hello it@example.com, camera 14 from Alex Morgan.');
        Mail::assertSent(FortValeEmail::class, fn (FortValeEmail $mail) => $mail->emailSubject === 'Camera 14 is down' && $mail->text === 'Hi Sam Okafor, see camera 14.');
        $this->assertSame(ActionRun::SUCCESS, ActionRun::sole()->status);
    }

    public function test_the_email_step_is_validated(): void
    {
        $this->post(route('actions.store', $this->alex), ['yaml' => "name: Bad\non: { run: }\nsteps:\n  - uses: email/send\n    with: { to: sam.okafor, subject: Hi }"])
            ->assertSessionHasErrors(['yaml' => 'steps[0].with: needs a template, or both subject and body.']);
        $this->post(route('actions.store', $this->alex), ['yaml' => "name: Bad\non: { run: }\nsteps:\n  - uses: email/send\n    with: { to: sam.okafor, subject: 'Hi \${{ recipient.age }}', body: x }"])
            ->assertSessionHasErrors('yaml');
    }

    public function test_personal_actions_cannot_send_without_the_owners_permission(): void
    {
        $action = Action::factory()->for($this->sam)->yaml("name: Mail\non: { run: }\nsteps:\n  - uses: email/send\n    with: { to: x@example.com, subject: Hi, body: Hi }")->create();

        $this->actingAs($this->sam)->post(route('actions.custom.run', [$this->sam, $action]));

        Mail::assertNothingSent();
        $this->assertSame(ActionRun::FAILED, ActionRun::sole()->status);
    }
}
