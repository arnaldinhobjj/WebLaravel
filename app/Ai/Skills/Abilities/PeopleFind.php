<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\User;

class PeopleFind extends Skill
{
    public function name(): string
    {
        return 'people.find';
    }

    public function title(): string
    {
        return 'Find a person';
    }

    public function description(): string
    {
        return 'Find colleagues by name, username or job title. Gives usernames for assigning tickets and sending email.';
    }

    public function properties(): array
    {
        return ['search' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Part of a name, username or job title. Empty lists everyone.']];
    }

    public function run(User $me, array $input): SkillResult
    {
        $term = mb_strtolower(trim((string) ($input['search'] ?? '')));
        $people = User::with('department')
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->whereRaw('lower(name) like ?', ["%{$term}%"])
                ->orWhereRaw('lower(username) like ?', ["%{$term}%"])
                ->orWhereRaw('lower(job_title) like ?', ["%{$term}%"])))
            ->orderBy('name')->limit(50)->get()
            ->map(fn (User $person): array => [
                'name' => $person->name,
                'username' => $person->username,
                'email' => $person->email,
                'job_title' => $person->job_title,
                'department' => $person->department?->name,
            ])->all();

        return SkillResult::ok(count($people).' '.str('person')->plural(count($people)).' found.', ['people' => $people]);
    }
}
