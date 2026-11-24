<?php

namespace App\Ai\Skills;

use App\Models\AiSetting;
use App\Models\AiTokenCall;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Every skill Fort Vale offers to AIs, in one neutral place. Adapters (MCP,
 * the HTTP/OpenAI endpoint, the /ai chat) read from here, so a skill is
 * written once and works with any AI.
 *
 * A skill is available to someone when they have its permissions and it
 * isn't switched off, by them (their own AIs) or by an administrator (for
 * everyone).
 */
class SkillRegistry
{
    /** @var list<class-string<Skill>> */
    public const ABILITIES = [
        Abilities\TicketsSearch::class,
        Abilities\TicketsGet::class,
        Abilities\TicketsMyQueue::class,
        Abilities\TicketsActivity::class,
        Abilities\TicketsStale::class,
        Abilities\TicketsCreate::class,
        Abilities\TicketsReply::class,
        Abilities\TicketsUpdate::class,
        Abilities\TicketsAssign::class,
        Abilities\PeopleMe::class,
        Abilities\PeopleFind::class,
        Abilities\DepartmentsList::class,
        Abilities\DepartmentsMembers::class,
        Abilities\FilesSearch::class,
        Abilities\FilesRecent::class,
        Abilities\FilesListFolder::class,
        Abilities\FilesRead::class,
        Abilities\FilesDetails::class,
        Abilities\FilesCreateFolder::class,
        Abilities\FilesSaveText::class,
        Abilities\FilesTrash::class,
        Abilities\EmailTemplates::class,
        Abilities\EmailSent::class,
        Abilities\EmailSend::class,
        Abilities\ActionsList::class,
        Abilities\ActionsExplain::class,
        Abilities\ActionsRecentRuns::class,
        Abilities\ActionsValidate::class,
        Abilities\ActionsRun::class,
        Abilities\HomeSummary::class,
    ];

    /** @var list<class-string<Recipe>> */
    public const RECIPES = [
        Recipes\SummariseTicket::class,
        Recipes\DraftReply::class,
        Recipes\TriageMyTickets::class,
        Recipes\MyDay::class,
        Recipes\WeeklyTicketReport::class,
        Recipes\FindDocument::class,
        Recipes\WriteAction::class,
        Recipes\EmailTicketUpdate::class,
        Recipes\HandoverNotes::class,
        Recipes\ExplainMyPermissions::class,
    ];

    /** @var array<string, Skill>|null */
    private ?array $abilities = null;

    /** @var array<string, Recipe>|null */
    private ?array $recipes = null;

    /**
     * @return array<string, Skill> by name
     */
    public function abilities(): array
    {
        return $this->abilities ??= collect(self::ABILITIES)->map(fn (string $class): Skill => app($class))->keyBy(fn (Skill $skill) => $skill->name())->all();
    }

    /**
     * @return array<string, Recipe> by name
     */
    public function recipes(): array
    {
        return $this->recipes ??= collect(self::RECIPES)->map(fn (string $class): Recipe => app($class))->keyBy(fn (Recipe $recipe) => $recipe->name())->all();
    }

    /** By readable name ("tickets.search") or tool name ("tickets_search"). */
    public function find(string $name): Skill|Recipe|null
    {
        $all = $this->abilities() + $this->recipes();

        return $all[$name] ?? collect($all)->first(fn (Skill|Recipe $skill): bool => $skill->toolName() === $name);
    }

    /**
     * Abilities this person's AIs may use now.
     *
     * @return array<string, Skill>
     */
    public function availableAbilities(User $me): array
    {
        $off = $this->switchedOff($me);

        return array_filter($this->abilities(), fn (Skill $skill): bool => ! in_array($skill->name(), $off, true) && $skill->allows($me));
    }

    /**
     * Recipes whose abilities are all available.
     *
     * @return array<string, Recipe>
     */
    public function availableRecipes(User $me): array
    {
        $off = $this->switchedOff($me);
        $abilities = $this->availableAbilities($me);

        return array_filter($this->recipes(), fn (Recipe $recipe): bool => ! in_array($recipe->name(), $off, true)
            && collect($recipe->uses())->every(fn (string $name): bool => isset($abilities[$name])));
    }

    public function isAvailable(User $me, Skill|Recipe $skill): bool
    {
        return $skill instanceof Skill
            ? isset($this->availableAbilities($me)[$skill->name()])
            : isset($this->availableRecipes($me)[$skill->name()]);
    }

    /**
     * Switched off for everyone, plus by this person.
     *
     * @return list<string>
     */
    public function switchedOff(User $me): array
    {
        return array_values(array_unique([...$this->switchedOffForEveryone(), ...$this->switchedOffBy($me)]));
    }

    /**
     * @return list<string>
     */
    public function switchedOffForEveryone(): array
    {
        return array_values((array) AppSetting::get('ai.disabled_skills', []));
    }

    /**
     * @return list<string>
     */
    public function switchedOffBy(User $me): array
    {
        return array_values((array) (AiSetting::find($me->id)?->disabled_skills ?? []));
    }

    /**
     * Runs an ability as the person: checks it's available and the input fits
     * its schema, never lets an error escape, and logs calls made with a token.
     *
     * @param  array<string, mixed>  $input
     */
    public function run(User $me, string $name, array $input, ?int $tokenId = null): SkillResult
    {
        $skill = $this->find($name);

        $result = match (true) {
            ! $skill instanceof Skill => SkillResult::fail("There's no skill called “{$name}”."),
            ! $this->isAvailable($me, $skill) => SkillResult::fail("You can't use “{$skill->name()}”: it needs a permission you don't have, or it's switched off."),
            default => $this->attempt($me, $skill, $input),
        };

        if ($tokenId !== null) {
            AiTokenCall::create([
                'token_id' => $tokenId,
                'tool' => $skill instanceof Skill ? $skill->toolName() : Str::limit($name, 55),
                'ok' => $result->ok,
                'summary' => Str::limit($result->summary, 290),
            ]);
        }

        return $result;
    }

    /**
     * Fills in a recipe's instructions after checking it's available.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function recipe(User $me, string $name, array $arguments): SkillResult
    {
        $recipe = $this->find($name);
        if (! $recipe instanceof Recipe || ! $this->isAvailable($me, $recipe)) {
            return SkillResult::fail("There's no recipe called “{$name}” that you can use.");
        }

        foreach ($recipe->arguments() as $argument => $definition) {
            if ($definition['required'] && trim((string) ($arguments[$argument] ?? '')) === '') {
                return SkillResult::fail("The recipe needs “{$argument}” ({$definition['description']}).");
            }
        }

        $clean = array_map(fn ($value) => Str::limit(trim((string) $value), 2000), array_intersect_key($arguments, $recipe->arguments()));

        return SkillResult::ok($recipe->title(), ['instructions' => $recipe->instructions($clean)]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function attempt(User $me, Skill $skill, array $input): SkillResult
    {
        try {
            return $skill->run($me, $this->validated($skill, $input));
        } catch (SkillFailed $failed) {
            return SkillResult::fail($failed->getMessage());
        } catch (ValidationException $invalid) {
            return SkillResult::fail(implode(' ', $invalid->validator->errors()->all()));
        } catch (Throwable $error) {
            report($error);

            return SkillResult::fail('Something went wrong in Fort Vale while doing that.');
        }
    }

    /**
     * Checks input against the skill's JSON Schema (types, required, enums,
     * lengths and ranges) and drops anything it doesn't declare.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(Skill $skill, array $input): array
    {
        $rules = [];
        foreach ($skill->properties() as $name => $property) {
            $rule = [in_array($name, $skill->required(), true) ? 'required' : 'nullable'];
            $rule[] = match ($property['type'] ?? 'string') {
                'integer' => 'integer',
                'number' => 'numeric',
                'boolean' => 'boolean',
                'array', 'object' => 'array',
                default => 'string',
            };
            if (isset($property['enum'])) {
                $rule[] = 'in:'.implode(',', $property['enum']);
            }
            if (isset($property['maxLength'])) {
                $rule[] = 'max:'.$property['maxLength'];
            }
            if (isset($property['minimum'])) {
                $rule[] = 'min:'.$property['minimum'];
            }
            if (isset($property['maximum'])) {
                $rule[] = 'max:'.$property['maximum'];
            }
            if (($property['type'] ?? null) === 'array' && isset($property['maxItems'])) {
                $rule[] = 'max:'.$property['maxItems'];
            }
            $rules[$name] = $rule;
        }

        return Validator::make($input, $rules)->validate();
    }
}
