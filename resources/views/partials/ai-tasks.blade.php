{{-- Badges for AI tasks started by actions (ai/report), on every page. See public/js/ai-tasks.js. --}}
<aside class="ai-tasks" aria-label="AI tasks" data-ai-tasks data-url="{{ route('ai.tasks.index') }}" hidden>
    <script type="application/json" data-ai-tasks-initial>@json(\App\Models\AiTask::withBadge($currentUser)->get()->map(fn ($task) => $task->toBadge())->values())</script>
    <button type="button" class="ai-tasks-caret" data-ai-tasks-toggle aria-expanded="true" aria-controls="ai-tasks-list">
        <span class="ai-tasks-count" data-ai-tasks-count hidden></span>
        <x-icon name="chevron-right" :size="16" />
        <span class="sr-only" data-ai-tasks-toggle-label>Hide AI tasks</span>
    </button>
    <ol class="ai-tasks-list" id="ai-tasks-list" data-ai-tasks-list></ol>
</aside>
<script src="{{ asset('js/ai-tasks.js') }}?v={{ filemtime(public_path('js/ai-tasks.js')) }}" defer></script>
