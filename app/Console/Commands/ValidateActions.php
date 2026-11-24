<?php

namespace App\Console\Commands;

use App\Services\Workflows\InvalidWorkflow;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowParser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('actions:validate {files?* : Action YAML files to check (default: every file in the actions folder)} {--personal : Check them as personal actions (no secrets) instead of shared ones}')]
#[Description('Checks action YAML files the same way Fort Vale does when it loads them, and lists every mistake')]
class ValidateActions extends Command
{
    public function handle(WorkflowParser $parser): int
    {
        $files = $this->argument('files') ?: glob(rtrim((string) config('fortvale.actions.path'), '/').'/*.{yml,yaml}', GLOB_BRACE);
        $source = $this->option('personal') ? Workflow::PERSONAL : Workflow::SHARED;
        $failed = 0;

        foreach ($files as $file) {
            if (! is_file($file)) {
                $this->error("{$file}: no such file.");
                $failed++;

                continue;
            }

            try {
                $workflow = $parser->parse((string) file_get_contents($file), $source, $source.':'.pathinfo($file, PATHINFO_FILENAME));
                $this->line("<info>OK</info>  {$file}: “{$workflow->name}” (".implode(', ', $workflow->triggers()).', '.count($workflow->steps).' '.str('step')->plural(count($workflow->steps)).')');
            } catch (InvalidWorkflow $invalid) {
                $failed++;
                $this->line("<error>FAIL</error> {$file}:");
                foreach ($invalid->errors as $error) {
                    $this->line("  - {$error}");
                }
            }
        }

        if ($files === []) {
            $this->warn('No action files found.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
