<?php

namespace Database\Seeders;

use App\Enums\DashboardVisibility;
use App\Enums\DashboardWidgetType;
use App\Enums\WidgetScope;
use App\Models\Dashboard;
use App\Models\Department;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowParser;
use App\Support\PagePermissions;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const URGENT_ACTION = <<<'YAML'
        name: My urgent tickets
        description: Urgent tickets assigned to me
        on:
          run:
            show_on_home: true
        steps:
          - uses: page/open
            with:
              url: /ticket?assignee=me&priority=urgent
        YAML;

    private const CAMERA_ACTION = <<<'YAML'
        name: Report a camera fault
        description: Start a CCTV ticket with the usual questions
        on:
          run:
            show_on_home: true
            inputs:
              camera:
                description: Camera number
                required: true
        steps:
          - uses: ticket/open-form
            with:
              subject: "Camera ${{ inputs.camera }} fault"
              category: cctv
              priority: high
              description: |
                Camera number: ${{ inputs.camera }}
                Location:
                When did it stop working?
        YAML;

    private const WAITING_ACTION = <<<'YAML'
        name: Waiting on others
        description: Tickets waiting on someone else
        on:
          run:
            show_on_home: true
        steps:
          - uses: page/open
            with:
              url: /ticket?status=waiting
        YAML;

    public function run(): void
    {
        $people = collect([
            ['Alex Morgan', 'Operations lead'],
            ['Derek Lamar', 'Quality manager'],
            ['Priya Natarajan', 'Dispatch coordinator'],
            ['Tom Achebe', 'Security officer'],
            ['Helena Brandt', 'Radiation protection'],
            ['Sam Okafor', 'IT support'],
        ])->mapWithKeys(fn ($person) => [
            $person[0] => User::factory()->create([
                'name' => $person[0],
                'username' => str($person[0])->lower()->replace(' ', '.')->toString(),
                'email' => str($person[0])->lower()->replace(' ', '.').'@fortvale.test',
                'job_title' => $person[1],
            ]),
        ]);

        $this->seedAccess($people);
        $this->seedDashboards($people);

        $parser = app(WorkflowParser::class);
        foreach ([self::URGENT_ACTION, self::CAMERA_ACTION, self::WAITING_ACTION] as $yaml) {
            $people['Alex Morgan']->actions()->make()
                ->fillFromWorkflow($parser->parse($yaml, Workflow::PERSONAL, 'personal:new'))
                ->save();
        }

        // [id, subject, category, priority, status, requester, assignee, days ago, description, comments]
        $tickets = [
            [37941, 'Confirm review of building C inspection checklist', 'quality', 'high', 'open', 'Derek Lamar', 'Alex Morgan', 2,
                "The inspection checklist for building C was updated after last month's findings. Every team lead needs to confirm they have reviewed it before Friday's audit.",
                [['Derek Lamar', 'Could you please confirm you have reviewed the updated inspection checklist for building C before Friday’s audit? We need sign-off from every team lead.']]],
            [37902, 'Overlapping crews on Tuesday dispatch schedule', 'dispatch', 'normal', 'in_progress', 'Priya Natarajan', 'Alex Morgan', 3,
                'The dispatch schedule for next week has crews B and D both booked on the Tuesday 06:00 departure.',
                [['Priya Natarajan', 'The dispatch schedule for next week has two overlapping crews on Tuesday. Can you take a look and pick one?']]],
            [37855, 'Camera 14 at north gate offline', 'cctv', 'urgent', 'waiting', 'Tom Achebe', 'Sam Okafor', 1,
                'Camera 14 (north gate) stopped streaming at 05:40. Facilities believes the switch in cabinet N2 has failed.',
                [['Sam Okafor', 'Switch in N2 is not responding. A contractor call-out needs approval.'], ['Tom Achebe', 'Camera 14 at the north gate has been offline since this morning. Facilities says it is a network issue — can you approve the call-out?']]],
            [37810, 'Question about March dosimetry readings', 'nuclear', 'normal', 'open', 'Helena Brandt', 'Alex Morgan', 6,
                'Follow-up on the Q1 dosimetry report: two badges in unit 2 show readings above the monthly average.',
                [['Helena Brandt', 'Thanks for the quick turnaround on the dosimetry report. One last question about the March readings, see the attached note.']]],
            [37796, 'Printer on floor 2 jams on double-sided jobs', 'it', 'normal', 'open', 'Alex Morgan', null, 1,
                'The HP printer next to meeting room 2B jams every time a double-sided job is sent. Single-sided works fine.', []],
            [37770, 'First aid kit restock in workshop', 'health', 'low', 'resolved', 'Tom Achebe', 'Helena Brandt', 9,
                'The workshop first aid kit is missing burn dressings and eye wash.',
                [['Helena Brandt', 'Restocked this morning and added a checklist to the lid.']]],
            [37731, 'Badge reader at loading dock rejects valid cards', 'facilities', 'high', 'in_progress', 'Priya Natarajan', 'Sam Okafor', 4,
                'Drivers with valid badges are being refused at the loading dock reader since the firmware update.', []],
            [37702, 'Request access to Quality audit folder', 'it', 'low', 'closed', 'Derek Lamar', 'Sam Okafor', 14,
                'New auditor needs read access to the Quality audit folder.', [['Sam Okafor', 'Access granted.']]],
        ];

        foreach ($tickets as [$id, $subject, $category, $priority, $status, $requester, $assignee, $daysAgo, $description, $comments]) {
            $created = Carbon::now()->subDays($daysAgo)->setTime(9, 15);

            $ticket = new Ticket([
                'subject' => $subject,
                'description' => $description,
                'category' => $category,
                'priority' => $priority,
                'status' => $status,
                'requester_id' => $people[$requester]->id,
                'assignee_id' => $assignee ? $people[$assignee]->id : null,
            ]);
            $ticket->id = $id;
            $ticket->created_at = $created;
            $ticket->resolved_at = in_array($status, ['resolved', 'closed']) ? $created->copy()->addDay() : null;
            $ticket->save();

            $at = $created->copy();
            $ticket->activities()->forceCreate(['type' => 'created', 'user_id' => $people[$requester]->id, 'created_at' => $at]);
            if ($assignee) {
                $ticket->activities()->forceCreate(['type' => 'assignee', 'user_id' => $people[$requester]->id, 'to_label' => $assignee, 'created_at' => $at->addMinutes(20)]);
            }
            foreach ($comments as [$author, $body]) {
                $ticket->activities()->forceCreate(['type' => 'comment', 'user_id' => $people[$author]->id, 'body' => $body, 'created_at' => $at->addHours(3)]);
            }
            if ($status !== 'open') {
                $ticket->activities()->forceCreate(['type' => 'status', 'user_id' => $people[$assignee ?? $requester]->id, 'from_label' => 'Open', 'to_label' => $ticket->status->label(), 'created_at' => $at->addHour()]);
            }

            $ticket->updated_at = $at;
            $ticket->saveQuietly();
        }
    }

    /**
     * A shared team dashboard and Alex's own, which opens at /dashboard for Alex.
     *
     * @param  Collection<string, User>  $people
     */
    private function seedDashboards($people): void
    {
        $alex = $people['Alex Morgan'];

        $team = new Dashboard(['name' => 'Team overview', 'visibility' => DashboardVisibility::Shared]);
        $team->user_id = $alex->id;
        $team->save();
        $team->addRecommendedWidgets();
        $team->addWidget(DashboardWidgetType::TicketsByCategory, WidgetScope::Everyone);
        $urgent = $team->addWidget(DashboardWidgetType::Table, WidgetScope::Everyone, 2, [
            'source' => 'tickets',
            'columns' => ['number', 'subject', 'assignee', 'updated'],
            'filters' => ['status' => 'active', 'priority' => 'urgent', 'sort' => 'oldest'],
            'limit' => 5,
        ]);
        $urgent->update(['title' => 'Urgent and still open']);

        $mine = new Dashboard(['name' => 'My work', 'visibility' => DashboardVisibility::Personal]);
        $mine->user_id = $alex->id;
        $mine->save();
        $mine->addRecommendedWidgets();

        $alex->forceFill(['default_dashboard_id' => $mine->id])->save();
    }

    /**
     * Departments, tags and who gets what. Seeded people sign in with the password "password".
     *
     * @param  Collection<string, User>  $people
     */
    private function seedAccess($people): void
    {
        $administrator = Tag::firstOrCreate(['name' => 'Administrator'], [
            'description' => 'Every permission.',
            'permissions' => array_keys(PagePermissions::all()),
        ]);
        $helpdesk = Tag::create(['name' => 'Helpdesk', 'description' => 'Picks up, changes and assigns every ticket.', 'permissions' => ['tickets.view', 'tickets.view_all', 'tickets.edit', 'tickets.assign']]);
        $editors = Tag::create(['name' => 'Action editors', 'description' => 'Maintains the shared actions.', 'permissions' => ['shared_actions.view', 'shared_actions.edit']]);

        $departments = [
            'Operations' => [[$editors], ['Alex Morgan', 'Derek Lamar', 'Priya Natarajan']],
            'Facilities' => [[], ['Tom Achebe', 'Helena Brandt']],
            'IT' => [[$helpdesk], ['Sam Okafor']],
        ];

        foreach ($departments as $name => [$tags, $members]) {
            $department = Department::create(['name' => $name]);
            $department->tags()->attach(collect($tags)->pluck('id'));
            foreach ($members as $member) {
                $people[$member]->update(['department_id' => $department->id]);
            }
        }

        $people['Alex Morgan']->tags()->syncWithoutDetaching([$administrator->id]);
    }
}
