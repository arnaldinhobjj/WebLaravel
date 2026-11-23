<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Tag;
use App\Support\AccessGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Departments and the tags they grant to everyone in them.
 */
class DepartmentController extends Controller
{
    public function create(): View
    {
        return view('tags.department-form', ['department' => new Department, 'tags' => Tag::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $department = Department::create(['name' => $data['name']]);
        $department->tags()->sync($data['tags']);

        return redirect()->route('tags.index')->with('status', "Department “{$department->name}” created.");
    }

    public function edit(Department $department): View
    {
        return view('tags.department-form', ['department' => $department->load('users'), 'tags' => Tag::orderBy('name')->get()]);
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $data = $this->validated($request, $department);

        AccessGuard::keepAManager(function () use ($department, $data) {
            $department->update(['name' => $data['name']]);
            $department->tags()->sync($data['tags']);
        }, 'tags');

        return redirect()->route('tags.index')->with('status', "Department “{$department->name}” saved.");
    }

    public function destroy(Department $department): RedirectResponse
    {
        AccessGuard::keepAManager(fn () => $department->delete(), 'tags');

        return redirect()->route('tags.index')->with('status', "Department “{$department->name}” deleted. Its people now have no department.");
    }

    /**
     * @return array{name: string, tags: list<int>}
     */
    private function validated(Request $request, ?Department $department = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique(Department::class)->ignore($department)],
            'tags' => ['array'],
            'tags.*' => ['integer', Rule::exists(Tag::class, 'id')],
        ]);

        return ['name' => $data['name'], 'tags' => array_map('intval', $data['tags'] ?? [])];
    }
}
