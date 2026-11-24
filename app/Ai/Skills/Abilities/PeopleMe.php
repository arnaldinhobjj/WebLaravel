<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\User;
use App\Support\PagePermissions;

class PeopleMe extends Skill
{
    public function name(): string
    {
        return 'people.me';
    }

    public function title(): string
    {
        return 'Who am I';
    }

    public function description(): string
    {
        return 'The person you are working for: name, username, email, job title, department, tags and what they are allowed to do in Fort Vale.';
    }

    public function run(User $me, array $input): SkillResult
    {
        return SkillResult::ok("You are working for {$me->name}.", [
            'name' => $me->name,
            'username' => $me->username,
            'email' => $me->email,
            'job_title' => $me->job_title,
            'department' => $me->department?->name,
            'tags' => $me->allTags()->pluck('name')->all(),
            'allowed' => PagePermissions::summary($me->permissions()),
        ]);
    }
}
