<?php

namespace App\Http\Controllers;

use App\Ai\Skills\Recipe;
use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillRegistry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fort Vale's skills for AIs that don't speak MCP: OpenAI-style tool
 * definitions, an OpenAPI spec (e.g. for ChatGPT custom actions), and plain
 * HTTP endpoints to run skills and fetch recipes. Uses the same personal AI
 * tokens as MCP; the AI acts as the token's owner.
 */
class SkillApiController extends Controller
{
    public function __construct(private SkillRegistry $registry) {}

    /**
     * Every skill the person may use, as OpenAI `tools`, plus the recipes.
     */
    public function index(Request $request): JsonResponse
    {
        $me = $this->me($request);

        return response()->json([
            'tools' => array_values(array_map(fn (Skill $skill): array => [
                'type' => 'function',
                'function' => [
                    'name' => $skill->toolName(),
                    'description' => $skill->description().($skill->changesThings() ? ' (Changes things: confirm with the person first.)' : ''),
                    'parameters' => $skill->inputSchema(),
                ],
            ], $this->registry->availableAbilities($me))),
            'recipes' => array_values(array_map(fn (Recipe $recipe): array => [
                'name' => $recipe->toolName(),
                'title' => $recipe->title(),
                'description' => $recipe->description(),
                'arguments' => $recipe->arguments(),
            ], $this->registry->availableRecipes($me))),
            'run' => url('/api/ai/skills/{name}'),
        ]);
    }

    public function openapi(Request $request): JsonResponse
    {
        $paths = [];
        foreach ($this->registry->availableAbilities($this->me($request)) as $skill) {
            $paths['/api/ai/skills/'.$skill->toolName()] = [
                'post' => [
                    'operationId' => $skill->toolName(),
                    'summary' => $skill->title(),
                    'description' => $skill->description(),
                    'x-changes-things' => $skill->changesThings(),
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $skill->inputSchema()]]],
                    'responses' => [
                        '200' => ['description' => 'Done', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Result']]]],
                        '422' => ['description' => 'Not done; the summary says why', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Result']]]],
                    ],
                ],
            ];
        }

        return response()->json([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Fort Vale skills', 'version' => '1.0.0', 'description' => 'Act in Fort Vale as the person whose token is used, within their permissions.'],
            'servers' => [['url' => rtrim(url('/'), '/')]],
            'security' => [['bearer' => []]],
            'paths' => (object) $paths,
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
                'schemas' => ['Result' => [
                    'type' => 'object',
                    'properties' => ['ok' => ['type' => 'boolean'], 'summary' => ['type' => 'string'], 'data' => ['type' => 'object']],
                ]],
            ],
        ]);
    }

    public function run(Request $request, string $name): JsonResponse
    {
        $result = $this->registry->run($this->me($request), $name, (array) $request->json()->all(), $request->attributes->get('ai_token_id'));

        return response()->json($result->toArray(), $result->ok ? 200 : 422);
    }

    public function recipe(Request $request, string $name): JsonResponse
    {
        $result = $this->registry->recipe($this->me($request), $name, $request->query());

        return response()->json($result->ok
            ? ['name' => $name, 'title' => $result->summary, 'instructions' => $result->data['instructions']]
            : $result->toArray(), $result->ok ? 200 : 404);
    }

    private function me(Request $request): User
    {
        return $request->user();
    }
}
