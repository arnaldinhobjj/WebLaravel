<?php

namespace App\Jobs;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillRegistry;
use App\Models\AiMessage;
use App\Models\AiSetting;
use App\Models\AiTask;
use App\Models\Folder;
use App\Services\Ai\ServerAi;
use App\Services\Files\FileStore;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Runs one AI task in the background: the AI writes in the task's chat (so
 * the person can follow along on /ai), may look things up with skills that
 * don't change anything, and its final answer is saved as a Markdown file
 * in the person's My files. A person's tasks run one after another.
 */
class RunAiTask implements ShouldQueue
{
    use Queueable;

    /** Skill uses before the AI must answer, as in the /ai chat. */
    public const MAX_SKILL_CALLS = 8;

    public int $timeout = 1200;

    /** A timeout fails the task at once (see failed()) instead of retrying it. */
    public bool $failOnTimeout = true;

    public function __construct(public AiTask $task) {}

    /**
     * Waiting for the person's previous task (WithoutOverlapping) releases the
     * job back to the queue, which counts as an attempt: retry by time instead.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('ai-tasks:'.$this->task->user_id))->releaseAfter(15)->expireAfter($this->timeout + 60)];
    }

    public function handle(ServerAi $ai, SkillRegistry $skills, FileStore $files): void
    {
        // Only a task still waiting starts (it may have been stopped since it was queued).
        $started = AiTask::whereKey($this->task->id)->where('status', AiTask::QUEUED)
            ->update(['status' => AiTask::WORKING, 'started_at' => now()]);
        $task = $this->task->fresh();
        if ($started === 0 || $task === null) {
            return;
        }

        try {
            $report = $this->converse($task, $ai, $skills);

            if ($this->stopRequested($task)) {
                $this->markStopped($task);

                return;
            }

            $this->save($task, $report, $files);
        } catch (Throwable $failure) {
            if (! $failure instanceof RuntimeException) {
                report($failure);
            }
            if ($this->stopRequested($task)) {
                $this->markStopped($task);

                return;
            }
            $task->update(['status' => AiTask::FAILED, 'error' => Str::limit($failure->getMessage(), 500), 'finished_at' => now()]);
        } finally {
            self::startNext($task->user_id);
        }
    }

    /**
     * When the job itself dies (timeout, worker stopped), the badge says so
     * and the person's next task can start.
     */
    public function failed(?Throwable $failure): void
    {
        $this->task->refresh();
        if ($this->task->status === AiTask::STOPPING) {
            $this->markStopped($this->task);
        } elseif ($this->task->isActive()) {
            $this->task->update(['status' => AiTask::FAILED, 'error' => 'The AI took too long or the job stopped.', 'finished_at' => now()]);
        }
        self::startNext($this->task->user_id);
    }

    /**
     * Whether Stop was pressed (the task is stopping, or was cancelled while waiting).
     */
    private function stopRequested(AiTask $task): bool
    {
        return in_array($task->fresh()?->status, [AiTask::STOPPING, AiTask::CANCELLED], true);
    }

    /**
     * Everything is written: now the chat on /ai can be used again.
     */
    private function markStopped(AiTask $task): void
    {
        $task->update(['status' => AiTask::CANCELLED, 'error' => 'Stopped.', 'finished_at' => now()]);
    }

    /**
     * Starts a new task now if the person has no earlier one running or
     * waiting; otherwise it waits its turn (see startNext()).
     */
    public static function startOrWait(AiTask $task): void
    {
        $earlier = AiTask::where('user_id', $task->user_id)
            ->whereIn('status', [AiTask::QUEUED, AiTask::WORKING, AiTask::STOPPING])
            ->where('id', '<', $task->id)
            ->exists();

        if (! $earlier) {
            self::dispatch($task);
        }
    }

    /**
     * Starts the person's oldest waiting task unless one is already running.
     * Called when a task ends, so only that task's successor is started.
     */
    public static function startNext(int $userId): void
    {
        if (AiTask::where('user_id', $userId)->whereIn('status', [AiTask::WORKING, AiTask::STOPPING])->exists()) {
            return;
        }

        $next = AiTask::where('user_id', $userId)->where('status', AiTask::QUEUED)->orderBy('id')->first();
        if ($next !== null) {
            self::dispatch($next);
        }
    }

    /**
     * The conversation: the AI answers, uses skills, answers again, until it
     * gives its report.
     */
    private function converse(AiTask $task, ServerAi $ai, SkillRegistry $skills): string
    {
        $person = $task->user;
        $connection = $task->connection ?? throw new RuntimeException('The AI this task used was removed.');
        $conversation = $task->conversation ?? throw new RuntimeException('The chat for this task was deleted.');

        // Nobody can confirm in the background, so only skills that look things up.
        $abilities = array_filter($skills->availableAbilities($person), fn (Skill $skill): bool => ! $skill->changesThings());
        $tools = array_values(array_map(fn (Skill $skill): array => [
            'name' => $skill->toolName(),
            'description' => $skill->description(),
            'schema' => $skill->inputSchema(),
        ], $abilities));
        $allowed = array_map(fn (Skill $skill): string => $skill->toolName(), $abilities);

        $history = $conversation->messages->map(fn (AiMessage $message): array => [
            'role' => $message->role, 'content' => (string) $message->content, ...($message->meta ?? []),
        ])->all();

        $system = $this->instructions($task, $tools !== []);

        for ($step = 0; $step <= self::MAX_SKILL_CALLS; $step++) {
            $reply = $ai->turn($connection, $system, $history, $step < self::MAX_SKILL_CALLS ? $tools : []);

            $history[] = $this->keep($conversation->id, $connection->model, [
                'role' => 'assistant', 'content' => $reply['text'], ...($reply['calls'] !== [] ? ['calls' => $reply['calls']] : []),
            ]);

            if ($reply['calls'] === []) {
                return trim($reply['text']) !== '' ? $reply['text'] : throw new RuntimeException('The AI finished without writing a report.');
            }

            foreach ($reply['calls'] as $index => $call) {
                if ($this->stopRequested($task)) {
                    // Every skill call needs a result, or the chat can't be continued on /ai.
                    foreach (array_slice($reply['calls'], $index) as $skipped) {
                        $this->keep($conversation->id, null, [
                            'role' => 'tool',
                            'content' => (string) json_encode(['ok' => false, 'summary' => 'Stopped before this skill ran.']),
                            'name' => $skipped['name'],
                            'ok' => false,
                            'call_id' => $skipped['id'],
                            'by' => 'ai',
                        ]);
                    }

                    return '';
                }

                $result = in_array($call['name'], $allowed, true)
                    ? $skills->run($person, $call['name'], $call['input'])->toArray()
                    : ['ok' => false, 'summary' => "“{$call['name']}” can't be used in the background: only skills that look things up."];

                $history[] = $this->keep($conversation->id, null, [
                    'role' => 'tool',
                    'content' => mb_substr((string) json_encode($result), 0, 20000),
                    'name' => $call['name'],
                    'ok' => $result['ok'],
                    'call_id' => $call['id'],
                    'by' => 'ai',
                ]);
            }
        }

        throw new RuntimeException('The AI kept using skills without writing the report (stopped after '.self::MAX_SKILL_CALLS.').');
    }

    private function instructions(AiTask $task, bool $hasTools): string
    {
        $parts = [
            'You are writing a report for '.$task->user->name.' in Fort Vale. This runs in the background: nobody can answer questions, so make reasonable assumptions and say what they were. Your final answer is saved as a Markdown file, so give the complete report, with a heading, and nothing else.',
        ];
        if ($hasTools) {
            $parts[] = 'You can use Fort Vale skills (tools) to look things up, within the person\'s permissions. Skills that create, change, send or delete anything are not available here. Each skill answers with "ok" and a "summary": if ok is false, say what you couldn\'t find and never guess the data instead.';
        }
        $own = trim((string) AiSetting::find($task->user_id)?->system_prompt);

        return implode("\n\n", array_filter([...$parts, $own]));
    }

    /**
     * Saves a message in the task's chat as soon as it happens, so /ai shows progress.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function keep(int $conversationId, ?string $model, array $message): array
    {
        ['role' => $role, 'content' => $content] = $message;
        $meta = array_diff_key($message, ['role' => true, 'content' => true]);

        AiMessage::create([
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => $content,
            'model' => $role === 'assistant' ? $model : null,
            'meta' => $meta !== [] ? $meta : null,
        ]);

        return $message;
    }

    private function save(AiTask $task, string $report, FileStore $files): void
    {
        $folder = Folder::personalRootFor($task->user);
        // The task number keeps each report its own file: the same name would add a version instead.
        $name = Str::limit(trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/', ' ', $task->title)), 80, '').' '.now()->format('Y-m-d H.i')." (report {$task->id}).md";
        $temporary = (string) tempnam(sys_get_temp_dir(), 'fortvale-ai-');
        file_put_contents($temporary, $report);

        try {
            $saved = $files->upload($task->user, $folder, new UploadedFile($temporary, $name, 'text/markdown', null, true));
        } catch (ValidationException $refused) {
            throw new RuntimeException('The report couldn\'t be saved: '.$refused->getMessage());
        } finally {
            @unlink($temporary);
        }

        $task->update(['status' => AiTask::DONE, 'file_id' => $saved['file']->id, 'folder_id' => $folder->id, 'finished_at' => now()]);
    }
}
