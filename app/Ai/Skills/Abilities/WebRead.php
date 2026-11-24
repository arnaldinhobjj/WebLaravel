<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillFailed;
use App\Ai\Skills\SkillResult;
use App\Models\User;
use App\Services\Web\WebPage;
use RuntimeException;

class WebRead extends Skill
{
    public function name(): string
    {
        return 'web.read';
    }

    public function title(): string
    {
        return 'Read a web page';
    }

    public function description(): string
    {
        return 'Read a public web page as plain text (up to '.number_format(WebPage::MAX_TEXT).' characters), e.g. a result from web.search. Only public internet addresses can be read.';
    }

    public function properties(): array
    {
        return ['url' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'The page address, starting with http:// or https://.']];
    }

    public function required(): array
    {
        return ['url'];
    }

    public function permissions(): array
    {
        return ['ai.web'];
    }

    public function run(User $me, array $input): SkillResult
    {
        try {
            $page = app(WebPage::class)->read($input['url']);
        } catch (RuntimeException $failed) {
            throw new SkillFailed($failed->getMessage());
        }

        return SkillResult::ok('Read '.($page['title'] ?: $page['url']).($page['truncated'] ? ' (shortened)' : '').'.', $page);
    }
}
