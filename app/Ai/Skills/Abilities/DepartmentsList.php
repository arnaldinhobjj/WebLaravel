<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\Department;
use App\Models\User;

class DepartmentsList extends Skill
{
    public function name(): string
    {
        return 'departments.list';
    }

    public function title(): string
    {
        return 'List departments';
    }

    public function description(): string
    {
        return 'Every department, with how many people are in it and the tags it gives them.';
    }

    public function permissions(): array
    {
        return ['departments.view'];
    }

    public function run(User $me, array $input): SkillResult
    {
        $departments = Department::with('tags')->withCount('users')->orderBy('name')->get()
            ->map(fn (Department $department): array => [
                'name' => $department->name,
                'people' => $department->users_count,
                'tags' => $department->tags->pluck('name')->all(),
            ])->all();

        return SkillResult::ok(count($departments).' departments.', ['departments' => $departments]);
    }
}
