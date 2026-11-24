<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Tag;
use App\Models\User;
use App\Support\AccessGuard;
use App\Support\PagePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The Tags page: tags and their permissions, departments and the tags they
 * grant, and people with what they can do.
 */
class TagController extends Controller
{
    public function index(): View
    {
        return view('tags.index', [
            'tags' => Tag::with('departments')->withCount('users')->orderByDesc('is_everyone')->orderBy('name')->get(),
            'departments' => Department::with(['tags', 'users'])->orderBy('name')->get(),
            'people' => User::with(['tags', 'department.tags'])->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('tags.form', ['tag' => new Tag(['permissions' => []])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tag = Tag::create($this->validated($request));

        return redirect()->route('tags.index')->with('status', "Tag “{$tag->name}” created.");
    }

    public function edit(Tag $tag): View
    {
        return view('tags.form', ['tag' => $tag]);
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        $data = $this->validated($request, $tag);
        if ($tag->is_everyone) {
            $data['name'] = $tag->name;
        }
        AccessGuard::keepAManager(fn () => $tag->update($data));

        return redirect()->route('tags.index')->with('status', "Tag “{$tag->name}” saved.");
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        if ($tag->is_everyone) {
            throw ValidationException::withMessages(['permissions' => 'The Everyone tag can’t be deleted. Untick its permissions instead.']);
        }

        AccessGuard::keepAManager(fn () => $tag->delete());

        return redirect()->route('tags.index')->with('status', "Tag “{$tag->name}” deleted.");
    }

    /**
     * @return array{name: string, description: ?string, permissions: list<string>}
     */
    private function validated(Request $request, ?Tag $tag = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique(Tag::class)->ignore($tag)],
            'description' => ['nullable', 'string', 'max:160'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(array_keys(PagePermissions::all()))],
        ]);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => PagePermissions::normalize($data['permissions'] ?? []),
        ];
    }
}
