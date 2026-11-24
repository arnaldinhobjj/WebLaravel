<?php

namespace App\Services\Workflows;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Workflows\Steps\StepRegistry;
use Cron\CronExpression;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads action YAML and checks it follows the action format, reporting every
 * problem with where it is (e.g. "steps[2].with.url: …").
 */
class WorkflowParser
{
    public const TICKET_EVENTS = ['created', 'status_changed', 'assigned', 'replied'];

    public const TICKET_FILTERS = ['category', 'priority', 'status', 'assignee'];

    private const MAX_LENGTH = 20000;

    private const MAX_STEPS = 20;

    public function __construct(private StepRegistry $steps) {}

    /**
     * @throws InvalidWorkflow
     */
    public function parse(string $yaml, string $source, string $key, ?int $ownerId = null): Workflow
    {
        if (mb_strlen($yaml) > self::MAX_LENGTH) {
            throw new InvalidWorkflow(['The action is too long (at most '.self::MAX_LENGTH.' characters).']);
        }

        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw new InvalidWorkflow(['YAML: '.$exception->getMessage()]);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidWorkflow(['The action must be a YAML map with `name`, `on` and `steps`.']);
        }

        $errors = [];

        foreach (array_diff(array_keys($data), ['name', 'description', 'on', 'steps']) as $unknown) {
            $errors[] = "{$unknown}: unknown setting. Allowed: name, description, on, steps.";
        }

        $name = $data['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            $errors[] = 'name: is required.';
        } elseif (mb_strlen($name) > 60) {
            $errors[] = 'name: must be at most 60 characters.';
        }

        $description = $data['description'] ?? null;
        if ($description !== null && (! is_string($description) || mb_strlen($description) > 160)) {
            $errors[] = 'description: must be text of at most 160 characters.';
        }

        [$run, $ticket, $schedules] = $this->parseTriggers($data['on'] ?? null, $errors);
        $steps = $this->parseSteps($data['steps'] ?? null, $source, $run, $ticket, $errors);

        if ($errors !== []) {
            throw new InvalidWorkflow($errors);
        }

        return new Workflow($source, $key, trim($name), $description, $run, $ticket, $schedules, $steps, $yaml, $ownerId);
    }

    /**
     * @param  list<string>  $errors
     * @return array{0: ?array, 1: ?array, 2: list<string>}
     */
    private function parseTriggers(mixed $on, array &$errors): array
    {
        if (! is_array($on) || array_is_list($on) || $on === []) {
            $errors[] = 'on: is required and needs at least one of run, ticket or schedule.';

            return [null, null, []];
        }

        foreach (array_diff(array_keys($on), ['run', 'ticket', 'schedule']) as $unknown) {
            $errors[] = "on.{$unknown}: unknown trigger. Allowed: run, ticket, schedule.";
        }

        $run = array_key_exists('run', $on) ? $this->parseRun($on['run'], $errors) : null;
        $ticket = array_key_exists('ticket', $on) ? $this->parseTicket($on['ticket'], $errors) : null;
        $schedules = array_key_exists('schedule', $on) ? $this->parseSchedule($on['schedule'], $errors) : [];

        return [$run, $ticket, $schedules];
    }

    /**
     * @param  list<string>  $errors
     */
    private function parseRun(mixed $run, array &$errors): array
    {
        $run ??= [];
        if (! is_array($run) || array_is_list($run) && $run !== []) {
            $errors[] = 'on.run: must be empty or a map with show_on_home and inputs.';

            return ['show_on_home' => false, 'inputs' => []];
        }

        foreach (array_diff(array_keys($run), ['show_on_home', 'inputs']) as $unknown) {
            $errors[] = "on.run.{$unknown}: unknown setting. Allowed: show_on_home, inputs.";
        }

        $showOnHome = $run['show_on_home'] ?? false;
        if (! is_bool($showOnHome)) {
            $errors[] = 'on.run.show_on_home: must be true or false.';
        }

        $inputs = [];
        $rawInputs = $run['inputs'] ?? [];
        if (! is_array($rawInputs) || array_is_list($rawInputs) && $rawInputs !== []) {
            $errors[] = 'on.run.inputs: must be a map of input names.';
            $rawInputs = [];
        }

        foreach ($rawInputs as $inputName => $input) {
            $path = "on.run.inputs.{$inputName}";
            if (! preg_match('/^[a-z][a-z0-9_]{0,30}$/', (string) $inputName)) {
                $errors[] = "{$path}: names use lowercase letters, numbers and _ (start with a letter).";
            }

            $input ??= [];
            if (! is_array($input)) {
                $errors[] = "{$path}: must be a map with description, required and default.";

                continue;
            }

            foreach (array_diff(array_keys($input), ['description', 'required', 'default']) as $unknown) {
                $errors[] = "{$path}.{$unknown}: unknown setting. Allowed: description, required, default.";
            }

            if (isset($input['required']) && ! is_bool($input['required'])) {
                $errors[] = "{$path}.required: must be true or false.";
            }

            if (isset($input['default']) && ! is_scalar($input['default'])) {
                $errors[] = "{$path}.default: must be text.";
            }

            $inputs[(string) $inputName] = [
                'description' => is_string($input['description'] ?? null) ? $input['description'] : (string) $inputName,
                'required' => ($input['required'] ?? false) === true,
                'default' => isset($input['default']) && is_scalar($input['default']) ? (string) $input['default'] : null,
            ];
        }

        return ['show_on_home' => $showOnHome === true, 'inputs' => $inputs];
    }

    /**
     * @param  list<string>  $errors
     */
    private function parseTicket(mixed $ticket, array &$errors): array
    {
        if (! is_array($ticket) || array_is_list($ticket)) {
            $errors[] = 'on.ticket: must be a map with types (and optionally if).';

            return ['types' => [], 'if' => []];
        }

        foreach (array_diff(array_keys($ticket), ['types', 'if']) as $unknown) {
            $errors[] = "on.ticket.{$unknown}: unknown setting. Allowed: types, if.";
        }

        $types = $ticket['types'] ?? null;
        if (! is_array($types) || $types === [] || array_diff($types, self::TICKET_EVENTS) !== []) {
            $errors[] = 'on.ticket.types: must list one or more of '.implode(', ', self::TICKET_EVENTS).'.';
            $types = [];
        }

        $filters = [];
        $if = $ticket['if'] ?? [];
        if (! is_array($if) || array_is_list($if) && $if !== []) {
            $errors[] = 'on.ticket.if: must be a map like `category: cctv`.';
            $if = [];
        }

        $allowed = [
            'category' => array_keys(Ticket::CATEGORIES),
            'priority' => array_column(TicketPriority::cases(), 'value'),
            'status' => array_column(TicketStatus::cases(), 'value'),
        ];

        foreach ($if as $field => $values) {
            $path = "on.ticket.if.{$field}";
            if (! in_array($field, self::TICKET_FILTERS, true)) {
                $errors[] = "{$path}: unknown filter. Allowed: ".implode(', ', self::TICKET_FILTERS).'.';

                continue;
            }

            $values = array_map('strval', (array) $values);
            if (isset($allowed[$field]) && array_diff($values, $allowed[$field]) !== []) {
                $errors[] = "{$path}: must be one or more of ".implode(', ', $allowed[$field]).'.';
            }

            $filters[$field] = array_values($values);
        }

        return ['types' => array_values(array_unique($types)), 'if' => $filters];
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function parseSchedule(mixed $schedule, array &$errors): array
    {
        if (! is_array($schedule) || ! array_is_list($schedule) || $schedule === []) {
            $errors[] = 'on.schedule: must be a list like `- cron: "0 9 * * 1"`.';

            return [];
        }

        $crons = [];
        foreach ($schedule as $index => $entry) {
            $cron = is_array($entry) ? ($entry['cron'] ?? null) : null;
            if (! is_string($cron) || ! CronExpression::isValidExpression($cron)) {
                $errors[] = "on.schedule[{$index}].cron: must be a valid cron expression, e.g. \"0 9 * * 1\" (Mondays 09:00).";

                continue;
            }
            $crons[] = $cron;
        }

        return $crons;
    }

    /**
     * @param  list<string>  $errors
     * @return list<array{id: ?string, name: string, uses: string, with: array<string, mixed>}>
     */
    private function parseSteps(mixed $steps, string $source, ?array $run, ?array $ticket, array &$errors): array
    {
        if (! is_array($steps) || ! array_is_list($steps) || $steps === []) {
            $errors[] = 'steps: is required and must be a list of steps.';

            return [];
        }

        if (count($steps) > self::MAX_STEPS) {
            $errors[] = 'steps: at most '.self::MAX_STEPS.' steps.';
        }

        $parsed = [];
        $earlierIds = [];
        $last = count($steps) - 1;

        foreach ($steps as $index => $step) {
            $path = "steps[{$index}]";
            if (! is_array($step) || array_is_list($step)) {
                $errors[] = "{$path}: must be a map with uses (and optionally id, name, with).";

                continue;
            }

            foreach (array_diff(array_keys($step), ['id', 'name', 'uses', 'with']) as $unknown) {
                $errors[] = "{$path}.{$unknown}: unknown setting. Allowed: id, name, uses, with.";
            }

            $id = $step['id'] ?? null;
            if ($id !== null && (! is_string($id) || ! preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $id))) {
                $errors[] = "{$path}.id: use lowercase letters, numbers, - and _ (start with a letter).";
                $id = null;
            } elseif ($id !== null && in_array($id, $earlierIds, true)) {
                $errors[] = "{$path}.id: \"{$id}\" is already used by an earlier step.";
            }

            $uses = $step['uses'] ?? null;
            if (! is_string($uses) || ! $this->steps->has($uses)) {
                $errors[] = "{$path}.uses: must be one of ".implode(', ', array_keys(StepRegistry::STEPS)).'.';

                continue;
            }

            $with = $step['with'] ?? [];
            if (! is_array($with) || array_is_list($with) && $with !== []) {
                $errors[] = "{$path}.with: must be a map of settings.";
                $with = [];
            }

            $handler = $this->steps->get($uses);
            array_push($errors, ...$handler->validate($with, "{$path}.with"));

            if ($handler->redirects() && ($run === null || $index !== $last)) {
                $errors[] = "{$path}.uses: {$uses} can only be the last step of an action with `on: run`.";
            }

            foreach (Expressions::references($with) as $reference) {
                $error = $this->checkReference($reference, $source, $run, $ticket, $earlierIds);
                if ($error !== null) {
                    $errors[] = "{$path}.with: \${{ {$reference} }}: {$error}";
                }
            }

            if ($id !== null) {
                $earlierIds[] = $id;
            }

            $parsed[] = [
                'id' => $id,
                'name' => is_string($step['name'] ?? null) ? $step['name'] : $uses,
                'uses' => $uses,
                'with' => $with,
            ];
        }

        return $parsed;
    }

    /**
     * @param  list<string>  $earlierIds
     */
    private function checkReference(string $reference, string $source, ?array $run, ?array $ticket, array $earlierIds): ?string
    {
        $parts = explode('.', $reference);
        $root = $parts[0];

        return match (true) {
            ! in_array($root, Expressions::ROOTS, true) => 'unknown value. Use inputs, ticket, actor, steps, secrets or (in email/send) recipient.',
            $root === 'recipient' && ! in_array($parts[1] ?? '', ['name', 'email'], true) => 'use recipient.name or recipient.email.',
            count($parts) < 2 => 'name a field, e.g. inputs.camera.',
            $root === 'inputs' && ! isset(($run['inputs'] ?? [])[$parts[1]]) => "no input named \"{$parts[1]}\" under on.run.inputs.",
            $root === 'ticket' && $ticket === null => 'ticket values are only available in actions with `on: ticket`.',
            $root === 'steps' && ! in_array($parts[1], $earlierIds, true) => "no earlier step with id \"{$parts[1]}\".",
            $root === 'secrets' && $source !== Workflow::SHARED => 'secrets can only be used in shared actions.',
            default => null,
        };
    }
}
