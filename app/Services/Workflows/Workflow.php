<?php

namespace App\Services\Workflows;

/**
 * A validated action definition, parsed from YAML.
 *
 * Shared actions come from `actions/*.yml` in the project; personal actions
 * are stored per person in the `actions` table.
 */
final readonly class Workflow
{
    public const SHARED = 'shared';

    public const PERSONAL = 'personal';

    /**
     * @param  array{show_on_home: bool, inputs: array<string, array{description: string, required: bool, default: ?string}>}|null  $run
     * @param  array{types: list<string>, if: array<string, list<string>>}|null  $ticket
     * @param  list<string>  $schedules  Cron expressions.
     * @param  list<array{id: ?string, name: string, uses: string, with: array<string, mixed>}>  $steps
     */
    public function __construct(
        public string $source,
        public string $key,
        public string $name,
        public ?string $description,
        public ?array $run,
        public ?array $ticket,
        public array $schedules,
        public array $steps,
        public string $yaml,
        public ?int $ownerId = null,
    ) {}

    public function isShared(): bool
    {
        return $this->source === self::SHARED;
    }

    public function canRunManually(): bool
    {
        return $this->run !== null;
    }

    public function showOnHome(): bool
    {
        return $this->run !== null && $this->run['show_on_home'];
    }

    /**
     * @return array<string, array{description: string, required: bool, default: ?string}>
     */
    public function inputs(): array
    {
        return $this->run['inputs'] ?? [];
    }

    /**
     * The triggers this action listens to, e.g. ['run', 'ticket'].
     *
     * @return list<string>
     */
    public function triggers(): array
    {
        return array_values(array_filter([
            $this->run !== null ? 'run' : null,
            $this->ticket !== null ? 'ticket' : null,
            $this->schedules !== [] ? 'schedule' : null,
        ]));
    }
}
