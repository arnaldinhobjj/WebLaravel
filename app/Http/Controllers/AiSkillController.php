<?php

namespace App\Http\Controllers;

use App\Ai\Skills\Recipe;
use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillRegistry;
use App\Models\AiSetting;
use App\Models\AppSetting;
use App\Support\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The Skills page (/ai/skills): every skill and recipe any AI can use, with
 * switches (yours, and for everyone), a form to try an ability, and the
 * endpoint the /ai chat uses to run skills as you.
 */
class AiSkillController extends Controller
{
    public function __construct(private SkillRegistry $registry) {}

    public function index(): View
    {
        $me = CurrentUser::get();

        return view('ai.skills', [
            'abilities' => collect($this->registry->abilities())->groupBy(fn (Skill $skill) => $skill->area()),
            'recipes' => $this->registry->recipes(),
            'available' => $this->registry->availableAbilities($me) + $this->registry->availableRecipes($me),
            'offByMe' => $this->registry->switchedOffBy($me),
            'offForEveryone' => $this->registry->switchedOffForEveryone(),
            'canManage' => Gate::allows('ai.manage_skills'),
            'tried' => session('skill_result'),
        ]);
    }

    /**
     * Switch a skill on or off for your own AIs, or (with permission) for everyone.
     */
    public function toggle(Request $request, string $name): RedirectResponse
    {
        $skill = $this->registry->find($name) ?? abort(404);
        $data = $request->validate(['on' => ['required', 'boolean'], 'for' => ['required', 'in:me,everyone']]);

        if ($data['for'] === 'everyone') {
            Gate::authorize('ai.manage_skills');
            AppSetting::put('ai.disabled_skills', $this->switch($this->registry->switchedOffForEveryone(), $skill->name(), (bool) $data['on']));
        } else {
            $settings = AiSetting::for(CurrentUser::get());
            $settings->disabled_skills = $this->switch($this->registry->switchedOffBy(CurrentUser::get()), $skill->name(), (bool) $data['on']);
            $settings->save();
        }

        $whose = $data['for'] === 'everyone' ? 'for everyone' : 'for your AIs';

        return redirect()->to(route('ai.skills').'#'.$skill->toolName())
            ->with('status', "“{$skill->title()}” switched ".($data['on'] ? 'on' : 'off')." {$whose}.");
    }

    /**
     * Run an ability as you: from the Try it form, or from the /ai chat (JSON).
     */
    public function run(Request $request, string $name): JsonResponse|RedirectResponse
    {
        $skill = $this->registry->find($name);
        $input = $request->expectsJson() ? (array) $request->json()->all() : $this->fromForm($skill, (array) $request->input('input', []));
        $result = $this->registry->run(CurrentUser::get(), $name, $input);

        if ($request->expectsJson()) {
            return response()->json($result->toArray(), $result->ok ? 200 : 422);
        }

        return redirect()->to(route('ai.skills').'#'.($skill?->toolName() ?? ''))
            ->with('skill_result', ['name' => $skill?->name() ?? $name] + $result->toArray());
    }

    /**
     * A recipe's instructions with your arguments filled in.
     */
    public function recipe(Request $request, string $name): JsonResponse|RedirectResponse
    {
        $result = $this->registry->recipe(CurrentUser::get(), $name, (array) $request->input('arguments', []));
        $recipe = $this->registry->find($name);

        if ($request->expectsJson()) {
            return response()->json($result->toArray(), $result->ok ? 200 : 422);
        }

        return redirect()->to(route('ai.skills').'#'.($recipe?->toolName() ?? ''))
            ->with('skill_result', ['name' => $recipe?->name() ?? $name] + $result->toArray());
    }

    /**
     * @param  list<string>  $off
     * @return list<string>
     */
    private function switch(array $off, string $name, bool $on): array
    {
        return array_values($on ? array_diff($off, [$name]) : array_unique([...$off, $name]));
    }

    /**
     * Turns the Try it form's text fields into the types the skill expects.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function fromForm(Skill|Recipe|null $skill, array $form): array
    {
        if (! $skill instanceof Skill) {
            return [];
        }

        $input = [];
        foreach ($skill->properties() as $name => $property) {
            $value = $form[$name] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $input[$name] = match ($property['type'] ?? 'string') {
                'integer' => is_numeric($value) ? (int) $value : $value,
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
                'array' => array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', (string) $value)))),
                'object' => json_decode((string) $value, true) ?? $value,
                default => (string) $value,
            };
        }

        return $input;
    }
}
