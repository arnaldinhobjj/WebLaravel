<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\EmailTemplate;
use App\Models\User;

class EmailTemplates extends Skill
{
    public function name(): string
    {
        return 'email.templates';
    }

    public function title(): string
    {
        return 'Email templates';
    }

    public function description(): string
    {
        return 'The email templates there are, with their subject and text. Use a template\'s name with email.send.';
    }

    public function permissions(): array
    {
        return ['email_templates.view'];
    }

    public function run(User $me, array $input): SkillResult
    {
        $templates = EmailTemplate::orderBy('name')->get()->map(fn (EmailTemplate $template): array => [
            'name' => $template->name,
            'built_in' => $template->isBuiltIn(),
            'subject' => $template->subject,
            'body' => $template->body,
        ])->all();

        return SkillResult::ok(count($templates).' templates.', ['templates' => $templates]);
    }
}
