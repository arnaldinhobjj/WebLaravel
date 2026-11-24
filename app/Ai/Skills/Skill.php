<?php

namespace App\Ai\Skills;

use App\Models\User;

/**
 * An ability: one thing any AI can do in Fort Vale, written once and offered
 * to every kind of AI (MCP apps, OpenAI-style tool calling, the /ai chat)
 * by the adapters. It always acts as the person, within their permissions.
 *
 * To add one: create a class in Abilities/, list it in SkillRegistry::ABILITIES.
 */
abstract class Skill
{
    /** Readable id, "area.what", e.g. "tickets.search". */
    abstract public function name(): string;

    abstract public function title(): string;

    /** What it does and when to use it, written for an AI and for people. */
    abstract public function description(): string;

    /**
     * Inputs as JSON Schema properties, e.g. ['ticket' => ['type' => 'integer', 'description' => '…']].
     *
     * @return array<string, array<string, mixed>>
     */
    public function properties(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function required(): array
    {
        return [];
    }

    /**
     * Fort Vale permissions the person needs, all of them ("page.ability").
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return [];
    }

    /** Creates, changes, sends or deletes something (AIs should confirm first). */
    public function changesThings(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $input  already checked against the schema
     */
    abstract public function run(User $me, array $input): SkillResult;

    public function allows(User $me): bool
    {
        foreach ($this->permissions() as $permission) {
            if (! $me->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /** "tickets_search": names many AI APIs accept (letters, digits, _ and -). */
    public function toolName(): string
    {
        return str_replace('.', '_', $this->name());
    }

    public function area(): string
    {
        return ucfirst(strtok($this->name(), '.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) $this->properties(),
            'required' => $this->required(),
            'additionalProperties' => false,
        ];
    }
}
