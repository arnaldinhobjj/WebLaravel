<?php

namespace Tests\Feature;

use App\Http\Controllers\AiTaskController;
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
    private function runReport(string $yaml, array $inputs = ['report' => 'Open IT tickets by priority'], string $source = Workflow::PERSONAL, bool $byAlex = true, string $trigger = 'run'): ActionRun
    {
        $workflow = app(WorkflowParser::class)->parse($yaml, $source, $source.':test', $source === Workflow::PERSONAL ? $this->alex->id : null);

        return app(WorkflowRunner::class)->run($workflow, $trigger, $byAlex ? $this->alex : null, $inputs)->run;
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

    public function test_a_host_that_turns_private_after_the_check_is_not_called(): void
    {
        // DNS rebinding: public for the step's and the turn's checks, loopback when the job connects.
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            private int $lookups = 0;

            public function addresses(string $host): array
            {
                return [++$this->lookups <= 2 ? '93.184.216.34' : '127.0.0.1'];
            }
        });
        $this->connection('https://rebind.example.com/v1');
        Http::fake();

        $this->runReport(self::REPORT);

        Http::assertNothingSent();
        $task = AiTask::sole();
        $this->assertSame(AiTask::FAILED, $task->status);
        $this->assertStringContainsString('may not call rebind.example.com', $task->error);
    }

    public function test_stopping_between_skill_calls_still_answers_every_call(): void
    {
        $this->connection();
        Http::fake(['localhost:11434/*' => function () {
            // The person presses Stop while the AI is answering with two skill calls.
            AiTask::query()->update(['status' => AiTask::STOPPING]);

            return Http::response(['choices' => [['message' => ['content' => '', 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'tickets_search', 'arguments' => '{}']],
                ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'people_me', 'arguments' => '{}']],
            ]]]]]);
        }]);

        $this->runReport(self::REPORT);

        $task = AiTask::sole();
        $this->assertSame([AiTask::CANCELLED, null], [$task->status, $task->file_id]);
        Http::assertSentCount(1);

        $results = $task->conversation->messages->where('role', 'tool')->values();
        $this->assertSame(['call_1', 'call_2'], $results->pluck('meta.call_id')->all());
        $this->assertSame([false, false], $results->pluck('meta.ok')->all());
    }

    public function test_stopping_a_running_report_keeps_the_chat_locked_until_the_job_has_finished(): void
    {
        Queue::fake();
        $this->connection();
        $this->runReport(self::REPORT, ['report' => 'First']);
        $this->runReport(self::REPORT, ['report' => 'Second']);
        [$running, $waiting] = AiTask::orderBy('id')->get()->all();
        $running->update(['status' => AiTask::WORKING]);

        // Still active ("Stopping…"), so /ai keeps polling and the next task waits.
        $this->actingAs($this->alex)->postJson(route('ai.tasks.cancel', $running))->assertOk()->assertJsonPath('badge.status', AiTask::STOPPING);
        $this->assertTrue($running->fresh()->isActive());
        $this->assertSame(AiTask::QUEUED, $waiting->fresh()->status);
        Queue::assertPushed(RunAiTask::class, 1);

        // The job's failure handler (or its normal end) finishes the stop and starts the next one.
        (new RunAiTask($running->fresh()))->failed(null);
        $this->assertSame(AiTask::CANCELLED, $running->fresh()->status);
        Queue::assertPushed(RunAiTask::class, fn (RunAiTask $job): bool => $job->task->is($waiting));
    }

    public function test_stop_pressed_just_as_the_report_finishes_does_not_reopen_it(): void
    {
        Queue::fake();
        $this->connection();
        $this->runReport(self::REPORT);
        $task = AiTask::sole();
        $task->update(['status' => AiTask::WORKING]);

        // The request loaded the task while it ran; the job finished before the stop was saved.
        $loadedByTheRequest = $task->fresh();
        $task->update(['status' => AiTask::DONE, 'finished_at' => now()]);
        $this->actingAs($this->alex);
        app(AiTaskController::class)->cancel($loadedByTheRequest);
        app(AiTaskController::class)->dismiss($loadedByTheRequest);

        $this->assertSame(AiTask::DONE, $task->fresh()->status);
        $this->assertFalse($task->fresh()->isActive());
    }

    public function test_two_reports_with_the_same_title_in_the_same_minute_get_their_own_files(): void
    {
        $this->connection();
        $this->travelTo(now()->startOfMinute());
        Http::fakeSequence('localhost:11434/*')
            ->push(['choices' => [['message' => ['content' => '# First']]]])
            ->push(['choices' => [['message' => ['content' => '# Second']]]]);

        $this->runReport(self::REPORT);
        $this->runReport(self::REPORT);

        [$first, $second] = AiTask::orderBy('id')->get()->all();
        $this->assertNotSame($first->file_id, $second->file_id);
        $this->assertSame('# First', Storage::disk('files')->get(StoredFile::findOrFail($first->file_id)->currentVersion->path));
        $this->assertSame('# Second', Storage::disk('files')->get(StoredFile::findOrFail($second->file_id)->currentVersion->path));
    }

    public function test_shared_actions_need_someone_pressing_run(): void
    {
        $this->connection();

        $run = $this->runReport(self::REPORT, source: Workflow::SHARED, trigger: 'schedule', byAlex: false);

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
