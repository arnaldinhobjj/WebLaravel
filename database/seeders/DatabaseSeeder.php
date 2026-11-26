<?php

namespace Database\Seeders;

use App\Enums\DashboardVisibility;
use App\Enums\DashboardWidgetType;
use App\Enums\WidgetScope;
use App\Models\Dashboard;
use App\Models\Department;
use App\Models\Material;
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
        $this->seedMaterials();

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

    /**
     * Four materials with their test results. The S355J2 flange fails its
     * Charpy minimum, so the out-of-spec state is visible.
     */
    private function seedMaterials(): void
    {
        $materials = [
            [
                'material' => ['code' => 'MAT-1001', 'name' => 'Carbon steel plate', 'part_name' => 'Pressure vessel shell plate, 40 mm', 'grade' => 'ASTM A516 Gr.70', 'heat_number' => 'H24A3317',
                    'description' => 'Normalised plate for moderate- and lower-temperature pressure vessels.',
                    'spec_min_yield_mpa' => 260, 'spec_min_uts_mpa' => 485, 'spec_max_uts_mpa' => 620, 'spec_min_elongation_pct' => 21,
                    'spec_min_charpy_avg_j' => 27, 'spec_min_charpy_single_j' => 20, 'charpy_temperature_c' => -46],
                'elements' => ['C' => [null, 0.27, 0.19], 'Mn' => [0.79, 1.30, 1.12], 'Si' => [0.13, 0.45, 0.31], 'P' => [null, 0.025, 0.012], 'S' => [null, 0.025, 0.004],
                    'Cr' => [null, 0.30, 0.05], 'Ni' => [null, 0.40, 0.08], 'Mo' => [null, 0.12, 0.02], 'Cu' => [null, 0.40, 0.11], 'V' => [null, 0.03, 0.004]],
                'tensile' => [['T1', 'Transverse', 342, 538, 27.5, 64.0], ['T2', 'Transverse', 351, 545, 26.0, 62.5], ['T3', 'Longitudinal', 358, 549, 28.5, 66.0]],
                'charpy' => [['C1', -46, 112, 1.21, 55], ['C2', -46, 98, 1.08, 50], ['C3', -46, 125, 1.33, 60]],
            ],
            [
                'material' => ['code' => 'MAT-1002', 'name' => 'Line pipe steel', 'part_name' => 'Seamless pipe, 12" sch 80', 'grade' => 'API 5L X65 PSL2', 'heat_number' => 'H24B0581',
                    'description' => 'Quenched and tempered line pipe for sour service.',
                    'spec_min_yield_mpa' => 450, 'spec_min_uts_mpa' => 535, 'spec_max_uts_mpa' => 760, 'spec_min_elongation_pct' => 18,
                    'spec_min_charpy_avg_j' => 40, 'spec_min_charpy_single_j' => 30, 'charpy_temperature_c' => -20],
                'elements' => ['C' => [null, 0.18, 0.08], 'Mn' => [null, 1.70, 1.42], 'Si' => [null, 0.45, 0.24], 'P' => [null, 0.025, 0.010], 'S' => [null, 0.015, 0.002],
                    'Cr' => [null, 0.50, 0.12], 'Ni' => [null, 0.50, 0.15], 'Mo' => [null, 0.50, 0.10], 'Cu' => [null, 0.50, 0.14], 'V' => [null, 0.10, 0.04], 'Nb' => [null, 0.05, 0.03], 'Ti' => [null, 0.04, 0.015]],
                'tensile' => [['T1', 'Transverse', 512, 604, 31.0, 74.0], ['T2', 'Transverse', 498, 596, 30.5, 72.5]],
                'charpy' => [['C1', -20, 236, 1.92, 100], ['C2', -20, 241, 1.98, 100], ['C3', -20, 228, 1.85, 95]],
            ],
            [
                'material' => ['code' => 'MAT-1003', 'name' => 'Structural steel', 'part_name' => 'Weld neck flange, DN200', 'grade' => 'EN 10025-2 S355J2', 'heat_number' => 'H24C1290',
                    'description' => 'Forged flange; Charpy results below the single-value minimum, held for review.',
                    'spec_min_yield_mpa' => 355, 'spec_min_uts_mpa' => 470, 'spec_max_uts_mpa' => 630, 'spec_min_elongation_pct' => 20,
                    'spec_min_charpy_avg_j' => 27, 'spec_min_charpy_single_j' => 19, 'charpy_temperature_c' => -20],
                'elements' => ['C' => [null, 0.20, 0.18], 'Mn' => [null, 1.60, 1.48], 'Si' => [null, 0.55, 0.38], 'P' => [null, 0.025, 0.021], 'S' => [null, 0.025, 0.011],
                    'Cu' => [null, 0.55, 0.28], 'Ni' => [null, 0.30, 0.10], 'Cr' => [null, 0.30, 0.09]],
                'tensile' => [['T1', 'Longitudinal', 372, 528, 23.0, 58.0], ['T2', 'Longitudinal', 366, 521, 22.5, 56.5]],
                'charpy' => [['C1', -20, 31, 0.42, 20], ['C2', -20, 17, 0.25, 10], ['C3', -20, 34, 0.47, 25]],
            ],
            [
                'material' => ['code' => 'MAT-1004', 'name' => 'Chromium-molybdenum alloy steel', 'part_name' => 'Pump shaft bar, Ø80 mm', 'grade' => 'AISI 4140 QT', 'heat_number' => 'H24D0742',
                    'description' => 'Quenched and tempered bar for rotating equipment.',
                    'spec_min_yield_mpa' => 655, 'spec_min_uts_mpa' => 860, 'spec_max_uts_mpa' => 1000, 'spec_min_elongation_pct' => 16,
                    'spec_min_charpy_avg_j' => 40, 'spec_min_charpy_single_j' => 32, 'charpy_temperature_c' => 20],
                'elements' => ['C' => [0.38, 0.43, 0.41], 'Mn' => [0.75, 1.00, 0.88], 'Si' => [0.15, 0.35, 0.26], 'P' => [null, 0.035, 0.014], 'S' => [null, 0.040, 0.018],
                    'Cr' => [0.80, 1.10, 0.97], 'Mo' => [0.15, 0.25, 0.21], 'Ni' => [null, 0.25, 0.09]],
                'tensile' => [['T1', 'Longitudinal', 712, 905, 19.0, 55.0], ['T2', 'Longitudinal', 698, 892, 18.5, 53.0]],
                'charpy' => [['C1', 20, 68, 0.71, 45], ['C2', 20, 74, 0.78, 50], ['C3', 20, 62, 0.66, 40]],
            ],
        ];

        foreach ($materials as $index => $data) {
            $material = Material::create($data['material']);
            $testedAt = Carbon::now()->subWeeks(10 - $index * 2);

            foreach ($data['elements'] as $element => [$min, $max, $measured]) {
                $material->elements()->create(['element' => $element, 'spec_min_pct' => $min, 'spec_max_pct' => $max, 'measured_pct' => $measured]);
            }
            foreach ($data['tensile'] as [$specimen, $orientation, $yield, $uts, $elongation, $reduction]) {
                $material->tensileTests()->create(['specimen' => $specimen, 'orientation' => $orientation, 'tested_at' => $testedAt, 'yield_mpa' => $yield, 'uts_mpa' => $uts, 'elongation_pct' => $elongation, 'reduction_of_area_pct' => $reduction]);
            }
            foreach ($data['charpy'] as [$specimen, $temperature, $energy, $expansion, $shear]) {
                $material->charpyTests()->create(['specimen' => $specimen, 'notch' => 'V', 'temperature_c' => $temperature, 'energy_j' => $energy, 'lateral_expansion_mm' => $expansion, 'shear_pct' => $shear, 'tested_at' => $testedAt->copy()->addDay()]);
            }
        }
    }
}
