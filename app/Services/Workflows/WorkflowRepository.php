<?php

namespace App\Services\Workflows;

use App\Models\Action;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Finds actions: shared ones from `actions/*.yml` in the project, and each
 * person's own from the database.
 */
class WorkflowRepository
{
    /** @var array<string, array{signature: string, result: Workflow|InvalidWorkflow}> */
    private static array $sharedCache = [];

    public function __construct(private WorkflowParser $parser) {}

    /**
     * Every shared action file, parsed, keyed by its file name without `.yml`.
     * Files with mistakes are returned as InvalidWorkflow so they can be shown.
     *
     * @return array<string, Workflow|InvalidWorkflow>
     */
    public function sharedWithErrors(): array
    {
        $files = glob(rtrim((string) config('fortvale.actions.path'), '/').'/*.{yml,yaml}', GLOB_BRACE) ?: [];
        sort($files);

        $actions = [];
        foreach ($files as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);
            if (! self::isValidSlug($slug)) {
                continue;
            }

            $signature = filemtime($file).':'.filesize($file);
            if ((self::$sharedCache[$file]['signature'] ?? null) !== $signature) {
                try {
                    $result = $this->parser->parse((string) file_get_contents($file), Workflow::SHARED, Workflow::SHARED.':'.$slug);
                } catch (InvalidWorkflow $invalid) {
                    $result = $invalid;
                }
                self::$sharedCache[$file] = ['signature' => $signature, 'result' => $result];
            }

            $actions[$slug] = self::$sharedCache[$file]['result'];
        }

        return $actions;
    }

    /**
     * @return Collection<string, Workflow>
     */
    public function shared(): Collection
    {
        return collect($this->sharedWithErrors())->filter(fn ($result): bool => $result instanceof Workflow);
    }

    /**
     * The file behind a shared action, valid or not, or null if there is none.
     */
    public function sharedFilePath(string $slug): ?string
    {
        if (! self::isValidSlug($slug)) {
            return null;
        }

        $folder = rtrim((string) config('fortvale.actions.path'), '/');

        foreach (['yml', 'yaml'] as $extension) {
            if (is_file("{$folder}/{$slug}.{$extension}")) {
                return "{$folder}/{$slug}.{$extension}";
            }
        }

        return null;
    }

    public function findShared(string $slug): ?Workflow
    {
        return self::isValidSlug($slug) ? $this->shared()->get($slug) : null;
    }

    /**
     * Run-button actions to show on a person's home screen: shared first, then theirs.
     *
     * @return Collection<int, Workflow>
     */
    public function forHome(User $person): Collection
    {
        $personal = $person->actions()->where('show_on_home', true)->get()->map(fn (Action $action): Workflow => $action->workflow());

        return $this->shared()->filter(fn (Workflow $workflow): bool => $workflow->showOnHome())->values()->concat($personal);
    }

    /**
     * Actions that should run for a ticket event. Personal actions only run
     * for tickets their owner opened or is assigned to.
     *
     * @return list<Workflow>
     */
    public function forTicketEvent(Ticket $ticket, string $event): array
    {
        $owners = array_filter([$ticket->requester_id, $ticket->assignee_id]);
        $personal = Action::whereJsonContains('triggers', 'ticket')
            ->whereIn('user_id', $owners)
            ->get()
            ->map(fn (Action $action): Workflow => $action->workflow());

        return $this->shared()->values()->concat($personal)
            ->filter(fn (Workflow $workflow): bool => $this->matches($workflow, $ticket, $event))
            ->values()
            ->all();
    }

    /**
     * Every action with a schedule.
     *
     * @return list<Workflow>
     */
    public function scheduled(): array
    {
        $personal = Action::whereJsonContains('triggers', 'schedule')->get()->map(fn (Action $action): Workflow => $action->workflow());

        return $this->shared()->filter(fn (Workflow $workflow): bool => $workflow->schedules !== [])
            ->values()->concat($personal)->values()->all();
    }

    public function matches(Workflow $workflow, Ticket $ticket, string $event): bool
    {
        if ($workflow->ticket === null || ! in_array($event, $workflow->ticket['types'], true)) {
            return false;
        }

        foreach ($workflow->ticket['if'] as $field => $allowed) {
            $actual = match ($field) {
                'category' => $ticket->category,
                'priority' => $ticket->priority->value,
                'status' => $ticket->status->value,
                'assignee' => $ticket->assignee_id ? User::whereKey($ticket->assignee_id)->value('username') : 'none',
            };

            if (! in_array($actual, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    public static function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,80}$/', $slug);
    }
}
