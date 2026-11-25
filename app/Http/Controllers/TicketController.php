<?php

namespace App\Http\Controllers;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\StoredFile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Files\FileStore;
use App\Support\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TicketController extends Controller
{
    public function index(Request $request): View
    {
        $me = CurrentUser::get();
        $filters = $request->only(['q', 'status', 'priority', 'category', 'assignee', 'requester', 'resolved_within', 'sort']);
        $status = $filters['status'] ?? 'active';

        $base = Ticket::query()->visibleTo($me)->tap(fn (Builder $query) => $this->applyFilters($query, $filters, $me));

        $counts = (clone $base)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $tickets = (clone $base)
            ->with(['requester', 'assignee'])
            ->withCount('comments')
            ->when($status === 'active', fn ($q) => $q->active())
            ->when(TicketStatus::tryFrom($status), fn ($q, $s) => $q->where('status', $s))
            ->tap(fn (Builder $query) => $this->applySort($query, $filters['sort'] ?? 'updated'))
            ->paginate(15)
            ->withQueryString();

        return view('tickets.index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'status' => $status,
            'counts' => $counts,
            'activeCount' => collect(TicketStatus::active())->sum(fn ($s) => $counts[$s->value] ?? 0),
            'users' => User::orderBy('name')->get(),
            'me' => $me,
        ]);
    }

    public function create(Request $request): View
    {
        return view('tickets.create', [
            'users' => User::orderBy('name')->get(),
            'prefill' => $request->only(['subject', 'category', 'priority', 'description']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:10000'],
            'category' => ['required', Rule::in(array_keys(Ticket::CATEGORIES))],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'assignee_id' => ['nullable', Rule::exists(User::class, 'id')],
            ...self::FILE_RULES,
        ]);
        $uploads = $this->acceptableUploads($request);

        $actor = CurrentUser::get();
        // Without the assign permission the field isn't shown, so a value is ignored.
        $assignee = $actor->can('tickets.assign') ? ($data['assignee_id'] ?? null) : null;
        unset($data['assignee_id'], $data['files']);

        $ticket = Ticket::open($data, $actor);
        foreach ($uploads as $upload) {
            app(FileStore::class)->attach($actor, 'ticket', (string) $ticket->id, $upload);
        }
        if ($assignee) {
            $ticket->applyChanges(['assignee_id' => (int) $assignee], $actor);
        }

        return redirect()->route('tickets.show', $ticket)->with('status', "Ticket #{$ticket->id} created.");
    }

    public function show(Ticket $ticket): View
    {
        $this->ensureVisible($ticket);
        $ticket->load(['requester', 'assignee', 'activities.user']);
        $replyIds = $ticket->activities->where('type', 'comment')->map(fn ($activity) => (string) $activity->id)->values()->all();

        return view('tickets.show', [
            'ticket' => $ticket,
            'replyFiles' => StoredFile::where('attachable_type', 'ticket_reply')->whereIn('attachable_id', $replyIds)->get()->groupBy('attachable_id'),
            'users' => User::orderBy('name')->get(),
            'me' => CurrentUser::get(),
        ]);
    }

    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureVisible($ticket);
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(TicketStatus::class)],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
            'category' => ['sometimes', Rule::in(array_keys(Ticket::CATEGORIES))],
            'assignee_id' => ['sometimes', 'nullable', Rule::exists(User::class, 'id')],
        ]);

        if (array_intersect_key($data, array_flip(['status', 'priority', 'category'])) !== []) {
            Gate::authorize('tickets.edit');
        }
        if (array_key_exists('assignee_id', $data)) {
            Gate::authorize('tickets.assign');
            $data['assignee_id'] = $data['assignee_id'] ? (int) $data['assignee_id'] : null;
        }

        $changed = $ticket->applyChanges($data, CurrentUser::get());

        return back()->with('status', $changed ? 'Ticket updated.' : 'Nothing changed.');
    }

    public function comment(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->ensureVisible($ticket);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            ...self::FILE_RULES,
        ]);
        $uploads = $this->acceptableUploads($request);

        if (! empty($data['status'])) {
            Gate::authorize('tickets.edit');
        }

        $actor = CurrentUser::get();
        $reply = $ticket->addComment($data['body'], $actor);
        foreach ($uploads as $upload) {
            app(FileStore::class)->attach($actor, 'ticket_reply', (string) $reply->id, $upload);
        }

        if (! empty($data['status'])) {
            $ticket->applyChanges(['status' => $data['status']], $actor);
        }

        return redirect()->to(route('tickets.show', $ticket).'#latest')->with('status', 'Reply posted.');
    }

    /** Optional files on a new ticket or a reply. */
    private const FILE_RULES = [
        'files' => ['nullable', 'array', 'max:10'],
        'files.*' => ['file'],
    ];

    /**
     * Checks every upload against the file limits before anything is saved.
     *
     * @return list<UploadedFile>
     */
    private function acceptableUploads(Request $request): array
    {
        $uploads = array_values($request->file('files', []));
        foreach ($uploads as $upload) {
            app(FileStore::class)->assertAcceptable($upload);
        }

        return $uploads;
    }

    /**
     * Other people's tickets answer 404 rather than 403, so ticket numbers don't reveal anything.
     */
    private function ensureVisible(Ticket $ticket): void
    {
        abort_unless($ticket->isVisibleTo(CurrentUser::get()), 404);
    }

    private function applyFilters(Builder $query, array $filters, User $me): void
    {
        $resolvedWithin = filter_var($filters['resolved_within'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        if ($resolvedWithin !== false) {
            $query->where('resolved_at', '>=', now()->subDays($resolvedWithin));
        }

        if ($search = trim($filters['q'] ?? '')) {
            $term = ltrim($search, '#');
            $query->where(function (Builder $q) use ($search, $term) {
                // LOWER on both sides: Oracle compares case-sensitively, MySQL doesn't.
                $pattern = '%'.mb_strtolower($search).'%';
                $q->whereRaw('lower(subject) like ?', [$pattern])
                    ->orWhereRaw('lower(description) like ?', [$pattern]);
                if (ctype_digit($term)) {
                    $q->orWhere('id', (int) $term);
                }
            });
        }

        if ($priority = TicketPriority::tryFrom($filters['priority'] ?? '')) {
            $query->where('priority', $priority);
        }

        if (isset(Ticket::CATEGORIES[$filters['category'] ?? ''])) {
            $query->where('category', $filters['category']);
        }

        match ($filters['assignee'] ?? null) {
            null, '' => null,
            'me' => $query->where('assignee_id', $me->id),
            'unassigned' => $query->whereNull('assignee_id'),
            default => $query->where('assignee_id', (int) $filters['assignee']),
        };

        if (($filters['requester'] ?? null) === 'me') {
            $query->where('requester_id', $me->id);
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'priority' => $query
                ->orderByRaw("case priority when 'urgent' then 4 when 'high' then 3 when 'normal' then 2 else 1 end desc")
                ->latest('updated_at'),
            'newest' => $query->latest('created_at')->latest('id'),
            'oldest' => $query->oldest('created_at')->oldest('id'),
            default => $query->latest('updated_at')->latest('id'),
        };
    }
}
