<?php

namespace Database\Factories;

use App\Models\Action;
use App\Models\User;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowParser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Action>
 */
class ActionFactory extends Factory
{
    /**
     * A Run-button action shown on the home screen that opens the urgent tickets.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['user_id' => User::factory()] + $this->fromYaml(<<<'YAML'
            name: Urgent queue
            on:
              run:
                show_on_home: true
            steps:
              - uses: page/open
                with:
                  url: /ticket?priority=urgent
            YAML);
    }

    /**
     * Uses the given action YAML (name, flags and triggers are taken from it).
     */
    public function yaml(string $yaml): static
    {
        return $this->state(fn (): array => $this->fromYaml($yaml));
    }

    /**
     * @return array<string, mixed>
     */
    private function fromYaml(string $yaml): array
    {
        $workflow = app(WorkflowParser::class)->parse($yaml, Workflow::PERSONAL, 'personal:new');

        return [
            'name' => $workflow->name,
            'yaml' => $yaml,
            'show_on_home' => $workflow->showOnHome(),
            'triggers' => $workflow->triggers(),
        ];
    }
}
