<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Tag;
use App\Models\User;
use App\Support\AccessGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Adding people and changing their department, extra tags and password.
 */
class PersonController extends Controller
{
    public function create(): View
    {
        return view('tags.person-form', $this->formData(new User));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $person = User::create($data['attributes']);
        $person->tags()->sync($data['tags']);

        return redirect()->route('tags.index')->with('status', "{$person->name} added. They can sign in as {$person->email}.");
    }

    public function edit(User $person): View
    {
        return view('tags.person-form', $this->formData($person));
    }

    public function update(Request $request, User $person): RedirectResponse
    {
        $data = $this->validated($request, $person);

        AccessGuard::keepAManager(function () use ($person, $data) {
            $person->update($data['attributes']);
            $person->tags()->sync($data['tags']);
        }, 'tags');

        return redirect()->route('tags.index')->with('status', "{$person->name} saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(User $person): array
    {
        return [
            'person' => $person,
            'departments' => Department::with('tags')->orderBy('name')->get(),
            'tags' => Tag::assignable()->orderBy('name')->get(),
        ];
    }

    /**
     * @return array{attributes: array<string, mixed>, tags: list<int>}
     */
    private function validated(Request $request, ?User $person = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)->ignore($person)],
            'job_title' => ['nullable', 'string', 'max:80'],
            'department_id' => ['nullable', 'integer', Rule::exists(Department::class, 'id')],
            'tags' => ['array'],
            'tags.*' => ['integer', Rule::exists(Tag::class, 'id')->where('is_everyone', false)],
            'password' => [$person ? 'nullable' : 'required', 'string', Password::defaults()],
        ]);

        $attributes = [
            'name' => $data['name'],
            'email' => $data['email'],
            'job_title' => $data['job_title'] ?? null,
            'department_id' => $data['department_id'] ?? null,
        ];
        if (! empty($data['password'])) {
            $attributes['password'] = $data['password'];
        }

        return ['attributes' => $attributes, 'tags' => array_map('intval', $data['tags'] ?? [])];
    }
}
