<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MultipleDatabasesTest extends TestCase
{
    use RefreshDatabase;

    private string $people;

    private string $tickets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->people = config('fortvale.connections.people');
        $this->tickets = config('fortvale.connections.tickets');

        if ($this->people === $this->tickets) {
            $this->markTestSkipped('People and tickets share one connection in this run.');
        }
    }

    public function test_stores_tickets_and_people_in_their_own_databases(): void
    {
        $requester = User::factory()->create();
        Action::factory()->for($requester)->create();
        $ticket = Ticket::factory()->create(['requester_id' => $requester->id]);
        $ticket->addComment('Checked the cable.', $requester);

        $this->assertSame($this->people, $requester->getConnectionName());
        $this->assertSame($this->tickets, $ticket->getConnectionName());

        $this->assertSame(1, DB::connection($this->tickets)->table('tickets')->count());
        $this->assertSame(1, DB::connection($this->tickets)->table('ticket_activities')->count());
        $this->assertSame(1, DB::connection($this->people)->table('users')->count());
        $this->assertSame(1, DB::connection($this->people)->table('actions')->count());

        $this->assertFalse(DB::connection($this->people)->getSchemaBuilder()->hasTable('tickets'));
        $this->assertFalse(DB::connection($this->tickets)->getSchemaBuilder()->hasTable('users'));
    }

    public function test_ticket_page_combines_ticket_and_people_data_from_both_databases(): void
    {
        $requester = User::factory()->create(['name' => 'Derek Lamar']);
        $assignee = User::factory()->create(['name' => 'Sam Okafor']);
        $ticket = Ticket::factory()->create([
            'subject' => 'Camera 14 offline',
            'requester_id' => $requester->id,
            'assignee_id' => $assignee->id,
        ]);
        $ticket->addComment('Switch in cabinet N2 has failed.', $assignee);

        $this->withSession(['acting_user_id' => $assignee->id])
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSeeInOrder(['Camera 14 offline', 'Opened by', 'Derek Lamar', 'Sam Okafor', 'Switch in cabinet N2 has failed.']);

        $this->withSession(['acting_user_id' => $assignee->id])
            ->get('/ticket?assignee=me')
            ->assertSee('Camera 14 offline')
            ->assertSee('Derek Lamar');
    }

    public function test_assigning_a_ticket_checks_the_person_exists_in_the_people_database(): void
    {
        $ticket = Ticket::factory()->create();
        $missingPersonId = User::max('id') + 1000;

        $this->patch(route('tickets.update', $ticket), ['assignee_id' => $missingPersonId])
            ->assertSessionHasErrors('assignee_id');

        $this->assertNull($ticket->fresh()->assignee_id);
    }
}
