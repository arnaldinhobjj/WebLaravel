<?php

namespace App\Services\Workflows\Steps;

use App\Jobs\RunAiTask;
use App\Models\AiConnection;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTask;
use App\Services\Ai\ServerAi;
use App\Services\Workflows\RunContext;
use Illuminate\Support\Str;

/**
 * `ai/report`: asks the person's AI for a report in the background. The AI
 * writes in a new chat on /ai (it may look things up with skills, but not
 * change anything), and its answer is saved as a Markdown file in their
 * My files. A badge on every page shows progress; the person's AI tasks run
 * one at a time. Output: `task` (its number).
 *
 * Needs a person: the owner of a personal action, or whoever pressed Run on
 * a shared one. `ai` picks one of their AIs by name; otherwise the AI of
 * their latest chat, or their first AI.
 */
class AiReportStep extends BaseStep
{
    public function __construct(private ServerAi $ai) {}

    protected function keys(): array
    {
        return ['prompt' => true, 'title' => false, 'ai' => false];
    }

    protected function checks(array $with, string $path): array
    {
        return [
            ...$this->plainText($with, 'prompt', $path, 4000),
            ...$this->plainText($with, 'title', $path, 120),
            ...$this->plainText($with, 'ai', $path, 60),
        ];
    }

    public function run(array $with, RunContext $context): array
    {
        if ($context->workflow->isShared() && $context->actor === null) {
            throw new StepFailed('Asking the AI needs a person: shared actions can only do it from the Run button.');
        }

        $person = $context->person(null);
        $context->ensureAllowed($person, ['ai.chat', 'my_files.view', 'my_files.create']);

        $prompt = trim((string) $with['prompt']);
        if ($prompt === '') {
            throw new StepFailed('There is nothing to ask the AI: the prompt is empty.');
        }

        $connection = $this->connectionFor($person->id, trim((string) ($with['ai'] ?? '')));
        if (($refusal = $this->ai->refusal($connection)) !== null) {
            throw new StepFailed($refusal);
        }

        $title = Str::limit(trim((string) ($with['title'] ?? '')) ?: $prompt, 80);

        $conversation = AiConversation::create(['user_id' => $person->id, 'connection_id' => $connection->id, 'title' => Str::limit($title, 60)]);
        AiMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => $prompt]);

        $task = AiTask::create([
            'user_id' => $person->id,
            'connection_id' => $connection->id,
            'conversation_id' => $conversation->id,
            'title' => $title,
            'prompt' => $prompt,
            'status' => AiTask::QUEUED,
        ]);
        RunAiTask::queue($task);

        $context->message ??= "Your AI is working on “{$title}”. It will be saved in My files.";

        return ['task' => $task->id];
    }

    private function connectionFor(int $userId, string $name): AiConnection
    {
        $connections = AiConnection::where('user_id', $userId)->orderBy('id')->get();

        if ($name !== '') {
            return $connections->first(fn (AiConnection $connection): bool => strcasecmp($connection->name, $name) === 0)
                ?? throw new StepFailed("There's no AI called “{$name}” under “Your AIs”.");
        }

        $latest = AiConversation::where('user_id', $userId)->whereNotNull('connection_id')->latest('updated_at')->latest('id')->value('connection_id');

        return $connections->firstWhere('id', $latest)
            ?? $connections->first(fn (AiConnection $connection): bool => (bool) $connection->model)
            ?? throw new StepFailed('Add an AI under “Your AIs” on the AI page first.');
    }
}
