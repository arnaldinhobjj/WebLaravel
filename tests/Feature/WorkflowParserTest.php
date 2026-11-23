<?php

namespace Tests\Feature;

use App\Services\Workflows\InvalidWorkflow;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowParserTest extends TestCase
{
    private function parse(string $yaml, string $source = Workflow::PERSONAL): Workflow
    {
        return app(WorkflowParser::class)->parse($yaml, $source, $source.':test', 7);
    }

    public function test_parses_every_trigger_input_and_step(): void
    {
        $workflow = $this->parse(<<<'YAML'
            name: Report a camera fault
            description: Start a CCTV ticket
            on:
              run:
                show_on_home: true
                inputs:
                  camera: { description: Camera number, required: true }
                  floor: { default: "1" }
              ticket:
                types: [created, replied]
                if: { category: cctv, priority: [high, urgent] }
              schedule:
                - cron: "0 9 * * 1"
            steps:
              - id: create
                uses: ticket/create
                with: { subject: "Camera ${{ inputs.camera }}", category: cctv }
              - uses: ticket/reply
                with: { ticket: "${{ steps.create.ticket }}", body: "Seen by ${{ actor.name }} on #${{ ticket.id }}" }
              - uses: page/open
                with: { url: /ticket }
            YAML);

        $this->assertSame('Report a camera fault', $workflow->name);
        $this->assertSame(['run', 'ticket', 'schedule'], $workflow->triggers());
        $this->assertTrue($workflow->showOnHome());
        $this->assertSame(['description' => 'Camera number', 'required' => true, 'default' => null], $workflow->inputs()['camera']);
        $this->assertSame('1', $workflow->inputs()['floor']['default']);
        $this->assertSame(['category' => ['cctv'], 'priority' => ['high', 'urgent']], $workflow->ticket['if']);
        $this->assertSame(['0 9 * * 1'], $workflow->schedules);
        $this->assertSame(['create', null, null], array_column($workflow->steps, 'id'));
        $this->assertSame(7, $workflow->ownerId);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidActions(): array
    {
        $steps = "steps:\n  - uses: message/show\n    with: { text: hi }\n";

        return [
            'not yaml' => ["name: [unclosed\n", 'YAML:'],
            'missing name' => ["on: { run: }\n".$steps, 'name: is required.'],
            'unknown top-level key' => ["name: x\nruns-on: ubuntu\non: { run: }\n".$steps, 'runs-on: unknown setting.'],
            'no trigger' => ["name: x\n".$steps, 'on: is required'],
            'unknown trigger' => ["name: x\non: { push: {} }\n".$steps, 'on.push: unknown trigger.'],
            'bad ticket type' => ["name: x\non: { ticket: { types: [opened] } }\n".$steps, 'on.ticket.types: must list'],
            'bad ticket filter value' => ["name: x\non: { ticket: { types: [created], if: { priority: blocker } } }\n".$steps, 'on.ticket.if.priority: must be one or more of'],
            'bad cron' => ["name: x\non: { schedule: [ { cron: every monday } ] }\n".$steps, 'on.schedule[0].cron: must be a valid cron expression'],
            'bad input name' => ["name: x\non: { run: { inputs: { Camera-Number: {} } } }\n".$steps, 'on.run.inputs.Camera-Number: names use lowercase'],
            'no steps' => ["name: x\non: { run: }\n", 'steps: is required'],
            'unknown step' => ["name: x\non: { run: }\nsteps:\n  - uses: shell/run\n", 'steps[0].uses: must be one of'],
            'unknown with key' => ["name: x\non: { run: }\nsteps:\n  - uses: message/show\n    with: { text: hi, colour: red }\n", 'steps[0].with.colour: unknown setting.'],
            'missing required with' => ["name: x\non: { run: }\nsteps:\n  - uses: ticket/reply\n", 'steps[0].with.body: is required.'],
            'bad category' => ["name: x\non: { run: }\nsteps:\n  - uses: ticket/create\n    with: { subject: s, category: snacks }\n", 'steps[0].with.category: must be one of'],
            'javascript link' => ["name: x\non: { run: }\nsteps:\n  - uses: page/open\n    with: { url: 'javascript:alert(1)' }\n", 'steps[0].with.url: must be a path'],
            'link with a newline' => ["name: x\non: { run: }\nsteps:\n  - uses: page/open\n    with: { url: \"/\\n/evil.com\" }\n", 'steps[0].with.url: must be a path'],
            'redirect step not last' => ["name: x\non: { run: }\nsteps:\n  - uses: page/open\n    with: { url: /ticket }\n  - uses: message/show\n    with: { text: hi }\n", 'steps[0].uses: page/open can only be the last step'],
            'redirect step without run trigger' => ["name: x\non: { ticket: { types: [created] } }\nsteps:\n  - uses: page/open\n    with: { url: /ticket }\n", 'can only be the last step of an action with `on: run`'],
            'http to non-web url' => ["name: x\non: { run: }\nsteps:\n  - uses: http/request\n    with: { url: 'ftp://x.example' }\n", 'steps[0].with.url: must be a web address'],
            'http timeout too long' => ["name: x\non: { run: }\nsteps:\n  - uses: http/request\n    with: { url: 'https://x.example', timeout: 60 }\n", 'steps[0].with.timeout: must be a whole number of seconds from 1 to 10.'],
            'unknown input reference' => ["name: x\non: { run: }\nsteps:\n  - uses: message/show\n    with: { text: '\${{ inputs.camera }}' }\n", 'no input named "camera"'],
            'ticket value without ticket trigger' => ["name: x\non: { run: }\nsteps:\n  - uses: message/show\n    with: { text: '\${{ ticket.id }}' }\n", 'only available in actions with `on: ticket`'],
            'later step reference' => ["name: x\non: { run: }\nsteps:\n  - uses: message/show\n    with: { text: '\${{ steps.later.ticket }}' }\n  - id: later\n    uses: message/show\n    with: { text: hi }\n", 'no earlier step with id "later"'],
            'personal secret' => ["name: x\non: { run: }\nsteps:\n  - uses: message/show\n    with: { text: '\${{ secrets.TOKEN }}' }\n", 'secrets can only be used in shared actions.'],
            'duplicate step id' => ["name: x\non: { run: }\nsteps:\n  - id: a\n    uses: message/show\n    with: { text: hi }\n  - id: a\n    uses: message/show\n    with: { text: hi }\n", 'steps[1].id: "a" is already used'],
        ];
    }

    #[DataProvider('invalidActions')]
    public function test_rejects_invalid_actions_with_the_location_of_the_mistake(string $yaml, string $expectedError): void
    {
        try {
            $this->parse($yaml);
            $this->fail('The action should have been rejected.');
        } catch (InvalidWorkflow $invalid) {
            $this->assertNotEmpty(array_filter($invalid->errors, fn (string $error): bool => str_contains($error, $expectedError)), implode("\n", $invalid->errors));
        }
    }

    public function test_shared_actions_may_use_secrets(): void
    {
        $workflow = $this->parse("name: x\non: { run: }\nsteps:\n  - uses: http/request\n    with: { url: 'https://hooks.example.com', headers: { Authorization: 'Bearer \${{ secrets.TOKEN }}' } }\n", Workflow::SHARED);

        $this->assertSame('http/request', $workflow->steps[0]['uses']);
    }

    public function test_every_shared_action_in_the_project_is_valid(): void
    {
        foreach (glob(base_path('actions/*.yml')) as $file) {
            $workflow = app(WorkflowParser::class)->parse(file_get_contents($file), Workflow::SHARED, 'shared:test');
            $this->assertNotSame('', $workflow->name, $file);
        }
    }
}
