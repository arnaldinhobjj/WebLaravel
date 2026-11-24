<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\SentEmail;
use App\Models\User;

class EmailSent extends Skill
{
    public function name(): string
    {
        return 'email.sent';
    }

    public function title(): string
    {
        return 'Emails I sent';
    }

    public function description(): string
    {
        return 'The emails the person sent recently (subject, recipients, when). Bodies aren\'t kept.';
    }

    public function permissions(): array
    {
        return ['emails.view'];
    }

    public function run(User $me, array $input): SkillResult
    {
        $sent = SentEmail::where('user_id', $me->id)->latest('id')->limit(20)->get()->map(fn (SentEmail $email): array => [
            'subject' => $email->subject,
            'to' => $email->to,
            'how' => $email->source,
            'at' => $email->created_at->toIso8601String(),
        ])->all();

        return SkillResult::ok(count($sent).' recent emails.', ['emails' => $sent]);
    }
}
