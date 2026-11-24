<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ValidateActionsTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/actions-'.uniqid());
        File::ensureDirectoryExists($this->folder);
        config(['fortvale.actions.path' => $this->folder]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_valid_files_pass_and_mistakes_are_listed_with_a_failing_exit_code(): void
    {
        File::put("{$this->folder}/hello.yml", "name: Hello\non: { run: }\nsteps:\n  - uses: message/show\n    with: { text: Hi }\n");
        $this->artisan('actions:validate')->expectsOutputToContain('“Hello” (run, 1 step)')->assertSuccessful();

        File::put("{$this->folder}/bad.yml", "name: Bad\non: { run: }\nsteps:\n  - uses: shell/run\n");
        $this->artisan('actions:validate')
            ->expectsOutputToContain('steps[0].uses: must be one of')
            ->assertFailed();
    }

    public function test_personal_actions_may_not_use_secrets(): void
    {
        $file = "{$this->folder}/secret.yml";
        File::put($file, "name: Hook\non: { run: }\nsteps:\n  - uses: http/request\n    with: { url: 'https://example.com/?k=\${{ secrets.TOKEN }}' }\n");

        $this->artisan('actions:validate', ['files' => [$file]])->assertSuccessful();
        $this->artisan('actions:validate', ['files' => [$file], '--personal' => true])
            ->expectsOutputToContain('secrets can only be used in shared actions')
            ->assertFailed();
    }
}
