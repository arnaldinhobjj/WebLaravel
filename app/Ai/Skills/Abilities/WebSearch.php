<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillFailed;
use App\Ai\Skills\SkillResult;
use App\Models\User;
use App\Services\Web\WebSearch as Search;
use RuntimeException;

class WebSearch extends Skill
{
    public function name(): string
    {
        return 'web.search';
    }

    public function title(): string
    {
        return 'Search the web';
    }

    public function description(): string
    {
        return 'Search the internet. Gives titles, addresses and short snippets; read a result with web.read for the details. Use it for current facts, documentation, suppliers, regulations and anything not in Fort Vale.';
    }

    public function properties(): array
    {
        return [
            'query' => ['type' => 'string', 'maxLength' => 300, 'description' => 'What to search for.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'How many results (default 5).'],
        ];
    }

    public function required(): array
    {
        return ['query'];
    }

    public function permissions(): array
    {
        return ['ai.web'];
    }

    public function run(User $me, array $input): SkillResult
    {
        $search = app(Search::class);

        try {
            $results = $search->search($input['query'], (int) ($input['limit'] ?? 5));
        } catch (RuntimeException $failed) {
            throw new SkillFailed($failed->getMessage());
        }

        return SkillResult::ok(count($results).' results for “'.$input['query'].'”.', ['provider' => $search->provider(), 'results' => $results]);
    }
}
