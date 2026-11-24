<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\ActionRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Workflows\HostResolver;
use App\Services\Workflows\RunOutcome;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowParser;
use App\Services\Workflows\WorkflowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowStepsTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alex = User::factory()->withPermissions('tickets.edit', 'tickets.assign')->create(['name' => 'Alex Morgan', 'username' => 'alex.morgan']);
        $this->fakeDns(['hooks.example.com' => '93.184.216.34', 'intranet.example.com' => '10.0.0.5']);
    }

    /**
     * @param  array<string, string>  $addresses
     */
    private function fakeDns(array $addresses): void
    {
        $this->app->instance(HostResolver::class, new class($addresses) extends HostResolver
        {
            public function __construct(private array $known) {}

            public function addresses(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (isset($this->known[$host]) ? [$this->known[$host]] : []);
            }
        });
    }

    /**
     * @param  array<string, string>  $inputs
     */
    private function runAction(string $yaml, array $inputs = [], ?Ticket $ticket = null, string $source = Workflow::PERSONAL): RunOutcome
    {
        $workflow = app(WorkflowParser::class)->parse($yaml, $source, $source.':test', $source === Workflow::PERSONAL ? $this->alex->id : null);

        return app(WorkflowRunner::class)->run($workflow, $ticket ? 'ticket' : 'run', $this->alex, $inputs, $ticket, $ticket ? 'created' : null);
    }

    public function test_creates_updates_and_replies_to_a_ticket_passing_values_between_steps(): void
    {
        $sam = User::factory()->create(['username' => 'sam.okafor', 'name' => 'Sam Okafor']);

        $outcome = $this->runAction(<<<'YAML'
            name: Camera fault
            on:
              run:
                inputs:
                  camera: { required: true }
            steps:
              - id: create
                uses: ticket/create
                with:
                  subject: "Camera ${{ inputs.camera }} offline"
                  category: cctv
                  priority: high
                  assignee: sam.okafor
              - uses: ticket/reply
                with:
                  ticket: "${{ steps.create.ticket }}"
                  body: "Logged by ${{ actor.name }}."
              - uses: ticket/update
                with:
                  ticket: "${{ steps.create.ticket }}"
                  status: waiting
                  assignee: none
            YAML, ['camera' => '14']);

        $this->assertTrue($outcome->run->succeeded(), json_encode($outcome->run->steps));
        $ticket = Ticket::where('subject', 'Camera 14 offline')->sole();
        $this->assertSame($this->alex->id, $ticket->requester_id);
        $this->assertSame('cctv', $ticket->category);
        $this->assertSame(TicketStatus::Waiting, $ticket->status);
        $this->assertNull($ticket->assignee_id);
        $this->assertSame('Logged by Alex Morgan.', $ticket->comments()->sole()->body);
        $this->assertSame(['ticket' => $ticket->id], $outcome->run->steps[0]['output']);
        $this->assertSame(['created', 'assignee', 'comment', 'status', 'assignee'], $ticket->activities()->pluck('type')->all());
        $this->assertSame(['Sam Okafor', null], $ticket->activities()->where('type', 'assignee')->pluck('to_label')->all());
        $this->assertNotNull($sam->id);
    }

    public function test_ticket_steps_default_to_the_triggering_ticket(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->alex->id]);

        $outcome = $this->runAction(<<<'YAML'
            name: Acknowledge
            on:
              ticket:
                types: [created]
            steps:
              - uses: ticket/reply
                with:
                  body: "On it: ${{ ticket.subject }}"
              - uses: ticket/update
                with:
                  status: in_progress
            YAML, ticket: $ticket);

        $this->assertTrue($outcome->run->succeeded());
        $this->assertSame("On it: {$ticket->subject}", $ticket->comments()->sole()->body);
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame($ticket->id, $outcome->run->ticket_id);
    }

    public function test_a_failing_step_stops_the_run_and_is_logged(): void
    {
        $outcome = $this->runAction(<<<'YAML'
            name: Assign to a ghost
            on:
              run:
            steps:
              - id: create
                uses: ticket/create
                with: { subject: Ghost ticket }
              - uses: ticket/update
                with: { ticket: "${{ steps.create.ticket }}", assignee: nobody.here }
              - uses: message/show
                with: { text: never shown }
            YAML);

        $this->assertSame(ActionRun::FAILED, $outcome->run->status);
        $this->assertSame(['success', 'failed'], array_column($outcome->run->steps, 'status'));
        $this->assertStringContainsString('No person with username "nobody.here"', $outcome->run->steps[1]['output']);
        $this->assertNull($outcome->message);
    }

    public function test_web_request_posts_json_to_a_public_address(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);

        $outcome = $this->runAction(<<<'YAML'
            name: Notify chat
            on:
              run:
            steps:
              - id: notify
                uses: http/request
                with:
                  url: https://hooks.example.com/abc
                  headers: { X-Source: fort-vale }
                  body: { text: "Hello from ${{ actor.username }}" }
            YAML);

        $this->assertTrue($outcome->run->succeeded(), json_encode($outcome->run->steps));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://hooks.example.com/abc'
            && $request->header('X-Source') === ['fort-vale']
            && $request['text'] === 'Hello from alex.morgan');
        $this->assertSame(['status' => 200, 'body' => 'ok'], $outcome->run->steps[0]['output']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function privateAddresses(): array
    {
        return [
            'local network name' => ['https://intranet.example.com/hook'],
            'loopback ip' => ['http://127.0.0.1:8000/ticket'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data'],
            'localhost' => ['http://localhost/hook'],
            'unresolvable' => ['https://does-not-exist.example.com/hook'],
        ];
    }

    #[DataProvider('privateAddresses')]
    public function test_web_request_refuses_addresses_that_are_not_public(string $url): void
    {
        Http::fake();

        $outcome = $this->runAction("name: Probe\non: { run: }\nsteps:\n  - uses: http/request\n    with: { url: '{$url}' }\n");

        $this->assertSame(ActionRun::FAILED, $outcome->run->status);
        $this->assertStringContainsString('is not a public internet address', $outcome->run->steps[0]['output']);
        Http::assertNothingSent();
    }

    public function test_web_request_fails_the_step_on_an_error_status(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('nope', 500)]);

        $outcome = $this->runAction("name: Notify\non: { run: }\nsteps:\n  - uses: http/request\n    with: { url: 'https://hooks.example.com/x' }\n  - uses: message/show\n    with: { text: done }\n");

        $this->assertSame(ActionRun::FAILED, $outcome->run->status);
        $this->assertSame('hooks.example.com answered 500.', $outcome->run->steps[0]['output']);
        $this->assertCount(1, $outcome->run->steps);
    }

    public function test_secrets_are_available_to_shared_actions_and_masked_in_the_log(): void
    {
        config(['fortvale.actions.secrets' => ['TOKEN' => 'super-secret-token']]);
        Http::fake(['hooks.example.com/*' => Http::response('echo super-secret-token', 200)]);

        $outcome = $this->runAction("name: Notify\non: { run: }\nsteps:\n  - uses: http/request\n    with: { url: 'https://hooks.example.com/x', headers: { Authorization: 'Bearer \${{ secrets.TOKEN }}' } }\n", source: Workflow::SHARED);

        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer super-secret-token']);
        $this->assertStringNotContainsString('super-secret-token', json_encode($outcome->run->fresh()->steps));
        $this->assertSame('echo ***', $outcome->run->steps[0]['output']['body']);
    }

    public function test_redirect_steps_only_run_from_the_run_button(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->alex->id]);
        $yaml = "name: Open\non: { run: , ticket: { types: [created] } }\nsteps:\n  - uses: page/open\n    with: { url: /ticket }\n";

        $this->assertSame('/ticket', $this->runAction($yaml)->redirect);

        $fromEvent = $this->runAction($yaml, ticket: $ticket);
        $this->assertNull($fromEvent->redirect);
        $this->assertSame('skipped', $fromEvent->run->steps[0]['status']);
    }
}
