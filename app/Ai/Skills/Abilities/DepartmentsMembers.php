<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillFailed;
use App\Ai\Skills\SkillResult;
use App\Models\Department;
use App\Models\User;

class DepartmentsMembers extends Skill
{
    public function name(): string
    {
        return 'departments.members';
    }

    public function title(): string
    {
        return 'Who is in a department';
    }

    public function description(): string
    {
        return 'The people in one department, by department name.';
    }

    public function properties(): array
    {
        return ['department' => ['type' => 'string', 'maxLength' => 60]];
    }

    public function required(): array
    {
        return ['department'];
    }

    public function permissions(): array
    {
        return ['departments.view'];
    }

    public function run(User $me, array $input): SkillResult
    {
        $department = Department::whereRaw('lower(name) = ?', [mb_strtolower(trim($input['department']))])->first()
            ?? throw new SkillFailed("No department called “{$input['department']}”. departments.list shows them.");

        $people = $department->users()->get()->map(fn (User $person): array => [
            'name' => $person->name, 'username' => $person->username, 'job_title' => $person->job_title,
        ])->all();

        return SkillResult::ok(count($people)." in {$department->name}.", ['department' => $department->name, 'people' => $people]);
    }
}
