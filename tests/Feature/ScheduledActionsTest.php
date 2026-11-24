<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Workflows\WorkflowScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduledActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fortvale.actions.path' => storage_path('framework/testing/no-shared-actions')]);
    }

    public function test_runs_scheduled_actions_only_in_their_minute_as_their_owner(): void
    {
        $owner = User::factory()->create(['username' => 'alex.morgan']);
        Action::factory()->for($owner)->yaml(<<<'YAML'
            name: Weekly printer check
            on:
              schedule:
                - cron: "0 9 * * 1"
            steps:
              - uses: ticket/create
                with: { subject: Weekly printer check, category: it }
            YAML)->create();

        $scheduler = app(WorkflowScheduler::class);
        $monday = Carbon::parse('next monday 09:00', config('app.timezone'));

        $this->assertSame(0, $scheduler->runDue($monday->copy()->addMinute()));
        $this->assertSame(1, $scheduler->runDue($monday));

        $ticket = Ticket::where('subject', 'Weekly printer check')->sole();
        $this->assertSame($owner->id, $ticket->requester_id);
        $this->assertSame(['schedule', $owner->id], [ActionRun::sole()->trigger, ActionRun::sole()->user_id]);
    }

    public function test_the_scheduler_checks_actions_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('fortvale-scheduled-actions')->assertSuccessful();
    }
}
