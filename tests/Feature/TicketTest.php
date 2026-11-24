<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->withPermissions('tickets.view_all')->create(['name' => 'Alex Morgan', 'job_title' => 'Operations lead']);
        $this->colleague = User::factory()->create(['name' => 'Sam Okafor', 'job_title' => 'IT support']);
        $this->actingAs($this->me);
    }

    public function test_index_lists_active_tickets_by_default(): void
    {
        Ticket::factory()->create(['subject' => 'Broken badge reader', 'status' => 'open']);
        Ticket::factory()->create(['subject' => 'Old printer request', 'status' => 'closed']);

        $this->get('/ticket')
            ->assertOk()
            ->assertSee('Broken badge reader')
            ->assertDontSee('Old printer request');

        $this->get('/ticket?status=all')->assertSee('Old printer request');
        $this->get('/ticket?status=closed')->assertSee('Old printer request')->assertDontSee('Broken badge reader');
    }

    public function test_index_filters_by_assignee_priority_and_search(): void
    {
        $mine = Ticket::factory()->create(['subject' => 'Mine to fix', 'assignee_id' => $this->me->id, 'priority' => 'urgent']);
        Ticket::factory()->create(['subject' => 'Someone else', 'assignee_id' => $this->colleague->id, 'priority' => 'low']);

        $this->get('/ticket?assignee=me')->assertSee('Mine to fix')->assertDontSee('Someone else');
        $this->get('/ticket?priority=low')->assertSee('Someone else')->assertDontSee('Mine to fix');
        $this->get('/ticket?q=%23'.$mine->id)->assertSee('Mine to fix')->assertDontSee('Someone else');
        $this->get('/ticket?q=else')->assertSee('Someone else')->assertDontSee('Mine to fix');
    }

    public function test_a_ticket_can_be_created(): void
    {
        $response = $this->post('/ticket', [
            'subject' => 'Printer on floor 3 is offline',
            'description' => 'It shows error 49.',
            'category' => 'it',
            'priority' => 'high',
            'assignee_id' => $this->colleague->id,
        ]);

        $ticket = Ticket::firstOrFail();
        $response->assertRedirect(route('tickets.show', $ticket));

        $this->assertSame($this->me->id, $ticket->requester_id);
        $this->assertSame($this->colleague->id, $ticket->assignee_id);
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame(['created', 'assignee'], $ticket->activities->pluck('type')->all());

        $this->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Printer on floor 3 is offline')
            ->assertSee('assigned this to Sam Okafor');
    }

    public function test_creating_a_ticket_validates_input(): void
    {
        $this->post('/ticket', ['subject' => '', 'description' => '', 'category' => 'nope', 'priority' => 'whenever'])
            ->assertSessionHasErrors(['subject', 'description', 'category', 'priority']);

        $this->assertDatabaseCount(Ticket::class, 0);
    }

    public function test_the_create_form_can_be_prefilled(): void
    {
        $this->get('/ticket/create?subject=Printer+support+needed&category=it')
            ->assertOk()
            ->assertSee('value="Printer support needed"', false)
            ->assertSee('<option value="it" selected', false);
    }

    public function test_changes_are_logged_and_resolving_sets_resolved_at(): void
    {
        $ticket = Ticket::factory()->create(['priority' => 'normal']);

        $this->patch(route('tickets.update', $ticket), ['status' => 'resolved', 'priority' => 'high', 'assignee_id' => $this->me->id])
            ->assertSessionHas('status', 'Ticket updated.');

        $ticket->refresh();
        $this->assertSame(TicketStatus::Resolved, $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertSame(['status', 'priority', 'assignee'], $ticket->activities->pluck('type')->all());

        $this->get(route('tickets.show', $ticket))
            ->assertSee('changed the status from Open to Resolved')
            ->assertSee('changed the priority from Normal to High');

        $this->patch(route('tickets.update', $ticket), ['status' => 'open']);
        $this->assertNull($ticket->fresh()->resolved_at);

        $this->patch(route('tickets.update', $ticket), ['status' => 'open'])->assertSessionHas('status', 'Nothing changed.');
    }

    public function test_replies_are_posted_by_the_current_user_and_can_change_status(): void
    {
        $ticket = Ticket::factory()->create();

        $this->post(route('tickets.comment', $ticket), ['body' => 'Looking into it now.', 'status' => 'in_progress'])
            ->assertRedirect(route('tickets.show', $ticket).'#latest');

        $comment = $ticket->comments()->firstOrFail();
        $this->assertSame($this->me->id, $comment->user_id);
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);

        $this->post(route('tickets.comment', $ticket), ['body' => ''])->assertSessionHasErrors('body');
    }

    public function test_people_only_see_tickets_they_opened_or_are_assigned_to(): void
    {
        $opened = Ticket::factory()->create(['subject' => 'Opened by Sam', 'requester_id' => $this->colleague->id]);
        $assigned = Ticket::factory()->create(['subject' => 'Assigned to Sam', 'assignee_id' => $this->colleague->id]);
        $other = Ticket::factory()->create(['subject' => 'Someone else’s ticket']);

        $this->actingAs($this->colleague);

        $this->get('/ticket?status=all')
            ->assertSee('Opened by Sam')
            ->assertSee('Assigned to Sam')
            ->assertDontSee('Someone else’s ticket');
        $this->get(route('tickets.show', $opened))->assertOk();
        $this->get(route('tickets.show', $assigned))->assertOk();
        $this->get(route('tickets.show', $other))->assertNotFound();
        $this->patch(route('tickets.update', $other), ['status' => 'closed'])->assertNotFound();
        $this->post(route('tickets.comment', $other), ['body' => 'Hi'])->assertNotFound();
        $this->assertSame(0, $other->comments()->count());
    }

    public function test_choosing_the_assignee_needs_the_assign_permission(): void
    {
        Tag::everyone()->update(['permissions' => ['tickets.view', 'tickets.create', 'tickets.edit']]);
        $ticket = Ticket::factory()->create(['requester_id' => $this->colleague->id]);
        $this->actingAs($this->colleague);

        $this->get(route('tickets.create'))->assertDontSee('name="assignee_id"', false);
        $this->get(route('tickets.show', $ticket))->assertDontSee('name="assignee_id"', false)->assertSee('name="status"', false);

        $this->post(route('tickets.store'), ['subject' => 'Printer', 'description' => 'Jams', 'category' => 'it', 'priority' => 'normal', 'assignee_id' => $this->me->id]);
        $this->assertNull(Ticket::where('subject', 'Printer')->sole()->assignee_id);

        $this->patch(route('tickets.update', $ticket), ['assignee_id' => $this->me->id])->assertForbidden();
        $this->patch(route('tickets.update', $ticket), ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->assertNull($ticket->fresh()->assignee_id);
    }

    public function test_seeing_every_ticket_comes_from_a_tag(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'Someone else’s ticket']);

        $this->actingAs($this->colleague)->get(route('tickets.show', $ticket))->assertNotFound();

        $this->colleague->tags()->attach(Tag::factory()->granting('tickets.view_all')->create());
        $this->actingAs($this->colleague->fresh())->get(route('tickets.show', $ticket))->assertOk();
    }

    public function test_unknown_ticket_returns_404(): void
    {
        $this->get('/ticket/999999')->assertNotFound();
    }

    public function test_search_ignores_letter_case(): void
    {
        Ticket::factory()->create(['subject' => 'Badge Reader at Loading Dock']);

        $this->get('/ticket?q=badge+reader')->assertSee('Badge Reader at Loading Dock');
        $this->get('/ticket?q=LOADING')->assertSee('Badge Reader at Loading Dock');
    }
}
