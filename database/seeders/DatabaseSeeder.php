<?php

namespace Database\Seeders;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

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

        $people['Alex Morgan']->actions()->createMany([
            [
                'name' => 'My urgent tickets',
                'description' => 'Urgent tickets assigned to me',
                'type' => 'link',
                'url' => '/ticket?assignee=me&priority=urgent',
            ],
            [
                'name' => 'Report a camera fault',
                'description' => 'Start a CCTV ticket with the usual questions',
                'type' => 'ticket',
                'ticket_subject' => 'Camera fault',
                'ticket_category' => 'cctv',
                'ticket_priority' => 'high',
                'ticket_description' => "Camera number:\nLocation:\nWhen did it stop working?",
            ],
            [
                'name' => 'Waiting on others',
                'description' => 'Tickets waiting on someone else',
                'type' => 'link',
                'url' => '/ticket?status=waiting',
            ],
        ]);

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
                $ticket->activities()->forceCreate(['type' => 'assignee', 'user_id' => $people[$requester]->id, 'to' => $assignee, 'created_at' => $at->addMinutes(20)]);
            }
            foreach ($comments as [$author, $body]) {
                $ticket->activities()->forceCreate(['type' => 'comment', 'user_id' => $people[$author]->id, 'body' => $body, 'created_at' => $at->addHours(3)]);
            }
            if ($status !== 'open') {
                $ticket->activities()->forceCreate(['type' => 'status', 'user_id' => $people[$assignee ?? $requester]->id, 'from' => 'Open', 'to' => $ticket->status->label(), 'created_at' => $at->addHour()]);
            }

            $ticket->updated_at = $at;
            $ticket->saveQuietly();
        }
    }
}
