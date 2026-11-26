<?php

namespace Tests\Feature;

use App\Jobs\RunAiTask;
use App\Mail\FortValeEmail;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\AiConnection;
use App\Models\AiConversation;
use App\Models\AiTask;
use App\Models\Folder;
use App\Models\StoredFile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Workflows\HostResolver;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowParser;
use App\Services\Workflows\WorkflowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiReportActionTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    private const REPORT = <<<'YAML'
        name: Ask the AI for a report
        on:
          run:
            inputs:
              report: { description: What report?, required: true }
        steps:
          - id: ask
            uses: ai/report
            with: { prompt: "${{ inputs.report }}" }
        YAML;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('files');
        $this->alex = User::factory()->create(['name' => 'Alex Morgan', 'username' => 'alex.morgan']);
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function addresses(string $host): array
            {
                return ['api.example.com' => ['93.184.216.34'], 'intranet.example.com' => ['10.0.0.5']][$host] ?? [];
            }
        });
    }

    private function connection(string $baseUrl = 'http://localhost:11434/v1', string $provider = 'openai'): AiConnection
    {
        return AiConnection::create(['user_id' => $this->alex->id, 'name' => 'Ollama', 'provider' => $provider, 'base_url' => $baseUrl, 'model' => 'qwen3:30b']);
    }

    /**
     * @param  array<string, string>  $inputs
     */
    private function runReport(string $yaml, array $inputs = ['report' => 'Open IT tickets by priority'], string $source = Workflow::PERSONAL, ?User $actor = null, string $trigger = 'run'): ActionRun
    {
        $workflow = app(WorkflowParser::class)->parse($yaml, $source, $source.':test', $source === Workflow::PERSONAL ? $this->alex->id : null);

        return app(WorkflowRunner::class)->run($workflow, $trigger, $actor ?? $this->alex, $inputs)->run;
    }

    public function test_the_ai_writes_the_report_in_a_chat_using_read_only_skills_and_saves_it_in_my_files(): void
    {
        $this->connection();
        Ticket::factory()->create(['subject' => 'Printer jam', 'category' => 'it', 'requester_id' => $this->alex->id]);

        Http::fakeSequence('localhost:11434/*')
            ->push(['choices' => [['message' => ['content' => '', 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'tickets_search', 'arguments' => '{"query":"printer"}']],
                ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'tickets_create', 'arguments' => '{"subject":"Sneaky"}']],
            ]]]]])
            ->push(['choices' => [['message' => ['content' => "# IT report\n\nOne open ticket: Printer jam."]]]]);

        $run = $this->runReport(self::REPORT);

        $this->assertSame(ActionRun::SUCCESS, $run->status, json_encode($run->steps));
        $task = AiTask::sole();
        $this->assertSame([AiTask::DONE, 'Open IT tickets by priority'], [$task->status, $task->title]);

        // Only skills that look things up are offered, and a changing one is refused.
        Http::assertSent(function (Request $request): bool {
            $tools = collect($request['tools'] ?? [])->pluck('function.name');

            return $tools->contains('tickets_search') && ! $tools->contains('tickets_create');
        });
        $this->assertSame(0, Ticket::where('subject', 'Sneaky')->count());

        // Every turn is in the chat, so /ai can show it.
        $messages = $task->conversation->messages;
        $this->assertSame(['user', 'assistant', 'tool', 'tool', 'assistant'], $messages->pluck('role')->all());
        $this->assertSame([true, false], [$messages[2]->meta['ok'], $messages[3]->meta['ok']]);

        $file = StoredFile::findOrFail($task->file_id);
        $this->assertTrue(Folder::personalRootFor($this->alex)->is($file->folder));
        $this->assertStringStartsWith('Open IT tickets by priority ', $file->name);
        $this->assertStringEndsWith('.md', $file->name);
        $this->assertSame("# IT report\n\nOne open ticket: Printer jam.", Storage::disk('files')->get($file->currentVersion->path));
    }

    public function test_the_server_only_calls_public_addresses_and_the_allowed_local_ai_ports(): void
    {
        Queue::fake();

        $this->connection('http://intranet.example.com/v1');
        $run = $this->runReport(self::REPORT);
        $this->assertSame(ActionRun::FAILED, $run->status);
        $this->assertStringContainsString('may not call intranet.example.com', json_encode($run->steps));

        AiConnection::query()->update(['base_url' => 'http://localhost:8080/v1']);
        $this->assertSame(ActionRun::FAILED, $this->runReport(self::REPORT)->status);

        AiConnection::query()->update(['base_url' => 'https://api.example.com/v1']);
        $this->assertSame(ActionRun::SUCCESS, $this->runReport(self::REPORT)->status);

        AiConnection::query()->update(['base_url' => 'http://localhost:11434/v1']);
        $this->assertSame(ActionRun::SUCCESS, $this->runReport(self::REPORT)->status);
        Queue::assertPushed(RunAiTask::class);
    }

    public function test_a_persons_tasks_run_one_at_a_time_in_order(): void
    {
        Queue::fake();
        $this->connection();

        $this->runReport(self::REPORT, ['report' => 'First']);
        $this->runReport(self::REPORT, ['report' => 'Second']);
        [$first, $second] = AiTask::orderBy('id')->get()->all();

        Queue::assertPushed(RunAiTask::class, 1);
        Queue::assertPushed(RunAiTask::class, fn (RunAiTask $job): bool => $job->task->is($first));

        // When the first one finishes, the second starts.
        $first->update(['status' => AiTask::WORKING]);
        RunAiTask::startNext($this->alex->id);
        Queue::assertPushed(RunAiTask::class, 1);

        $first->update(['status' => AiTask::DONE]);
        RunAiTask::startNext($this->alex->id);
        Queue::assertPushed(RunAiTask::class, fn (RunAiTask $job): bool => $job->task->is($second));
    }

    public function test_a_failing_ai_shows_the_error_on_the_badge(): void
    {
        $this->connection();
        Http::fake(['localhost:11434/*' => Http::response('model "qwen3:30b" not found', 404)]);

        $this->runReport(self::REPORT);

        $task = AiTask::sole();
        $this->assertSame(AiTask::FAILED, $task->status);
        $this->assertStringContainsString('answered 404', $task->error);
        $this->assertNull($task->file_id);
    }

    public function test_shared_actions_need_someone_pressing_run(): void
    {
        $this->connection();

        $run = $this->runReport(self::REPORT, source: Workflow::SHARED, trigger: 'schedule', actor: null);

        $this->assertSame(ActionRun::FAILED, $run->status);
        $this->assertStringContainsString('only do it from the Run button', json_encode($run->steps));
        $this->assertSame(0, AiTask::count());
    }

    public function test_the_shipped_action_validates_and_asks_for_the_report(): void
    {
        $this->artisan('actions:validate', ['files' => [base_path('actions/ai-report.yml'), base_path('actions/ticket-being-looked-at.yml')]])->assertSuccessful();
    }

    public function test_badges_are_the_owners_only_and_can_be_seen_stopped_and_closed(): void
    {
        Queue::fake();
        $this->connection();
        $this->runReport(self::REPORT);
        $task = AiTask::sole();

        $this->actingAs($this->alex)->get('/')->assertSee('data-ai-tasks', false)->assertSee('Open IT tickets by priority');
        $this->getJson(route('ai.tasks.index'))->assertOk()->assertJsonPath('tasks.0.status', AiTask::QUEUED);
        $this->getJson(route('ai.tasks.show', $task))->assertOk()->assertJsonPath('messages.0.content', 'Open IT tickets by priority');
        $this->get(route('ai.index', ['c' => $task->conversation_id]))->assertSee('data-task-url', false);

        $sam = User::factory()->create();
        $this->actingAs($sam)->getJson(route('ai.tasks.index'))->assertJsonCount(0, 'tasks');
        $this->getJson(route('ai.tasks.show', $task))->assertNotFound();
        $this->deleteJson(route('ai.tasks.dismiss', $task))->assertNotFound();

        $this->actingAs($this->alex)->postJson(route('ai.tasks.cancel', $task))->assertOk()->assertJsonPath('badge.status', AiTask::CANCELLED);
        $this->deleteJson(route('ai.tasks.dismiss', $task))->assertOk();
        $this->getJson(route('ai.tasks.index'))->assertJsonCount(0, 'tasks');
        $this->assertNotNull($task->fresh()->seen_at);
        $this->assertTrue(AiConversation::whereKey($task->conversation_id)->exists());
    }

    public function test_the_requester_is_emailed_when_someone_is_assigned_to_their_ticket(): void
    {
        Mail::fake();
        $this->withoutDefer();
        config(['fortvale.actions.path' => base_path('actions')]);
        $sam = User::factory()->withPermissions('tickets.edit', 'tickets.assign')->create(['name' => 'Sam Okafor', 'username' => 'sam.okafor']);
        $isLookingAt = fn (Ticket $ticket) => fn (FortValeEmail $mail): bool => $mail->hasTo($this->alex->email)
            && $mail->emailSubject === "Your ticket #{$ticket->id} is being looked at"
            && str_contains($mail->text, 'A member of our team is having a look');

        // Opened without anyone assigned: no email yet; assigned later: emailed.
        $later = Ticket::open(['subject' => 'Printer jam', 'description' => 'x', 'category' => 'it'], $this->alex);
        Mail::assertNotSent(FortValeEmail::class, $isLookingAt($later));
        $later->applyChanges(['assignee_id' => $sam->id], $sam);
        Mail::assertSent(FortValeEmail::class, $isLookingAt($later));

        // Opened already assigned: emailed too.
        $assigned = Ticket::open(['subject' => 'Badge reader', 'description' => 'x', 'category' => 'it', 'assignee_id' => $sam->id], $this->alex);
        Mail::assertSent(FortValeEmail::class, $isLookingAt($assigned));
    }

    public function test_assignee_anyone_matches_only_assigned_tickets(): void
    {
        $this->withoutDefer();
        config(['fortvale.actions.path' => storage_path('framework/testing/no-shared-actions')]);
        Action::factory()->for($this->alex)->yaml(<<<'YAML'
            name: Note assigned tickets
            on:
              ticket:
                types: [created]
                if: { assignee: anyone }
            steps:
              - uses: ticket/reply
                with: { body: Assigned }
            YAML)->create();

        $unassigned = Ticket::open(['subject' => 'A', 'description' => 'x', 'category' => 'it'], $this->alex);
        $assigned = Ticket::open(['subject' => 'B', 'description' => 'x', 'category' => 'it', 'assignee_id' => $this->alex->id], $this->alex);

        $this->assertSame(0, $unassigned->comments()->count());
        $this->assertSame(1, $assigned->comments()->count());
    }
}
