<?php

namespace App\Http\Controllers;

use App\Jobs\RunAiTask;
use App\Models\AiMessage;
use App\Models\AiTask;
use App\Support\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Background AI tasks started by actions (`ai/report`): the badges every
 * page shows, and what the /ai page polls while a task writes in a chat.
 * Everything is the owner's only.
 */
class AiTaskController extends Controller
{
    /**
     * The person's badges: running, waiting, and finished ones not closed yet.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'tasks' => AiTask::withBadge(CurrentUser::get())->get()->map(fn (AiTask $task): array => $task->toBadge())->values(),
        ]);
    }

    /**
     * A task's status and the chat messages after `after`, for the /ai page.
     */
    public function show(Request $request, AiTask $task): JsonResponse
    {
        $this->own($task);

        $messages = $task->conversation_id === null ? collect() : AiMessage::where('conversation_id', $task->conversation_id)
            ->where('id', '>', $request->integer('after'))
            ->orderBy('id')
            ->get()
            ->map(fn (AiMessage $message): array => ['id' => $message->id, 'role' => $message->role, 'content' => $message->content, 'meta' => $message->meta]);

        return response()->json(['status' => $task->status, 'error' => $task->error, 'badge' => $task->toBadge(), 'messages' => $messages->values()]);
    }

    /**
     * The person has seen that it finished (its badge no longer counts as new).
     */
    public function seen(AiTask $task): JsonResponse
    {
        $this->own($task);
        if (! $task->isActive() && $task->seen_at === null) {
            $task->update(['seen_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Closes a badge. A running or waiting task is cancelled first.
     */
    public function dismiss(AiTask $task): JsonResponse
    {
        $this->own($task);
        if ($task->isActive()) {
            $this->stop($task);
        }
        $task->update(['dismissed_at' => now(), 'seen_at' => $task->seen_at ?? now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * Stops a running or waiting task; the chat keeps what was written so far.
     */
    public function cancel(AiTask $task): JsonResponse
    {
        $this->own($task);
        if ($task->isActive()) {
            $this->stop($task);
        }

        return response()->json(['ok' => true, 'badge' => $task->fresh()->toBadge()]);
    }

    /**
     * A running task becomes "stopping": its job notices at its next step,
     * finishes writing the chat (so /ai stays locked until then), marks it
     * cancelled and starts the next task. A waiting one is cancelled at once.
     */
    private function stop(AiTask $task): void
    {
        if ($task->status !== AiTask::QUEUED) {
            $task->update(['status' => AiTask::STOPPING]);

            return;
        }

        $task->update(['status' => AiTask::CANCELLED, 'error' => 'Stopped.', 'finished_at' => now()]);
        RunAiTask::startNext($task->user_id);
    }

    private function own(AiTask $task): void
    {
        abort_unless($task->user_id === CurrentUser::get()->id, 404);
    }
}
