<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TicketEventActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    private User $derek;

    private const ACKNOWLEDGE_CCTV = <<<'YAML'
        name: Acknowledge CCTV
        on:
          ticket:
            types: [created]
            if: { category: cctv }
        steps:
          - uses: ticket/reply
            with: { body: "Thanks ${{ actor.name }}, looking at #${{ ticket.id }}." }
          - uses: ticket/update
            with: { status: in_progress }
        YAML;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();
        config(['fortvale.actions.path' => storage_path('framework/testing/no-shared-actions')]);

        $this->alex = User::factory()->create(['name' => 'Alex Morgan', 'username' => 'alex.morgan']);
        $this->derek = User::factory()->create(['name' => 'Derek Lamar', 'username' => 'derek.lamar']);
    }

    public function test_personal_actions_do_not_run_automatically_once_their_owner_may_not_run_actions(): void
    {
        Action::factory()->for($this->alex)->yaml(self::ACKNOWLEDGE_CCTV)->create();
        Tag::everyone()->update(['permissions' => ['tickets.view', 'tickets.create']]);

        $this->actingAs($this->alex)
            ->post('/ticket', ['subject' => 'Camera 9 offline', 'description' => 'No picture', 'category' => 'cctv', 'priority' => 'high']);

        $this->assertSame(0, Ticket::where('subject', 'Camera 9 offline')->sole()->comments()->count());
        $this->assertSame(0, ActionRun::count());
    }

    public function test_runs_a_personal_action_when_its_owner_opens_a_matching_ticket(): void
    {
        Action::factory()->for($this->alex)->yaml(self::ACKNOWLEDGE_CCTV)->create();

        $this->actingAs($this->alex)
            ->post('/ticket', ['subject' => 'Camera 9 offline', 'description' => 'No picture', 'category' => 'cctv', 'priority' => 'high'])
            ->assertRedirect();

        $ticket = Ticket::where('subject', 'Camera 9 offline')->sole();
        $this->assertSame("Thanks Alex Morgan, looking at #{$ticket->id}.", $ticket->comments()->sole()->body);
        $this->assertSame(TicketStatus::InProgress, $ticket->status);

        $run = ActionRun::sole();
        $this->assertSame(['ticket', 'created', $ticket->id, ActionRun::SUCCESS], [$run->trigger, $run->event, $run->ticket_id, $run->status]);
    }

    public function test_does_not_run_when_the_filter_does_not_match(): void
    {
        Action::factory()->for($this->alex)->yaml(self::ACKNOWLEDGE_CCTV)->create();

        Ticket::open(['subject' => 'Printer jam', 'description' => 'x', 'category' => 'it'], $this->alex);

        $this->assertSame(0, ActionRun::count());
    }

    public function test_personal_actions_only_run_for_tickets_their_owner_opened_or_is_assigned(): void
    {
        Action::factory()->for($this->alex)->yaml(self::ACKNOWLEDGE_CCTV)->create();

        $derekTicket = Ticket::open(['subject' => 'Not Alex’s camera', 'description' => 'x', 'category' => 'cctv'], $this->derek);
        $this->assertSame(0, ActionRun::count());
        $this->assertSame(0, $derekTicket->comments()->count());

        $assigned = Ticket::open(['subject' => 'Assigned camera', 'description' => 'x', 'category' => 'cctv', 'assignee_id' => $this->alex->id], $this->derek);
        $this->assertSame(1, ActionRun::where('ticket_id', $assigned->id)->count());
    }

    public function test_changes_made_by_an_action_do_not_trigger_more_actions(): void
    {
        Action::factory()->for($this->alex)->yaml(self::ACKNOWLEDGE_CCTV)->create();
        Action::factory()->for($this->alex)->yaml(<<<'YAML'
            name: Reopen when someone starts work
            on:
              ticket:
                types: [status_changed, replied]
            steps:
              - uses: ticket/update
                with: { status: open }
            YAML)->create();

        $ticket = Ticket::open(['subject' => 'Camera loop', 'description' => 'x', 'category' => 'cctv'], $this->alex);

        $this->assertSame(['Acknowledge CCTV'], ActionRun::pluck('action_name')->all());
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);

        $ticket->fresh()->applyChanges(['status' => 'waiting'], $this->alex);
        $this->assertSame(1, ActionRun::where('action_name', 'Reopen when someone starts work')->count());
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
    }

    public function test_shared_actions_run_for_every_matching_ticket(): void
    {
        $folder = storage_path('framework/testing/actions-'.uniqid());
        File::ensureDirectoryExists($folder);
        File::put("{$folder}/triage.yml", "name: Triage urgent\non: { ticket: { types: [created], if: { priority: urgent } } }\nsteps:\n  - uses: ticket/update\n    with: { assignee: alex.morgan }\n");
        config(['fortvale.actions.path' => $folder]);

        try {
            $ticket = Ticket::open(['subject' => 'Fire alarm', 'description' => 'x', 'category' => 'facilities', 'priority' => 'urgent'], $this->derek);
        } finally {
            File::deleteDirectory($folder);
        }

        $this->assertSame($this->alex->id, $ticket->fresh()->assignee_id);
        $this->assertSame($this->derek->id, ActionRun::sole()->user_id);
    }
}
