<?php

namespace App\Http\Controllers;

use App\Services\Workflows\InvalidWorkflow;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowParser;
use App\Services\Workflows\WorkflowRepository;
use App\Support\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Shows and edits a shared action: the YAML file in `actions/`. Saving writes
 * the file, after checking it is valid and hasn't changed on disk since the
 * page was opened.
 */
class SharedActionController extends Controller
{
    public function __construct(private WorkflowRepository $workflows) {}

    public function edit(string $slug): View
    {
        $path = $this->workflows->sharedFilePath($slug) ?? abort(404);
        $yaml = (string) file_get_contents($path);
        $result = $this->workflows->sharedWithErrors()[$slug] ?? null;

        return view('actions.shared', [
            'slug' => $slug,
            'fileName' => 'actions/'.basename($path),
            'yaml' => $yaml,
            'hash' => sha1($yaml),
            'workflow' => $result instanceof Workflow ? $result : null,
            'fileErrors' => $result instanceof InvalidWorkflow ? $result->errors : [],
            'currentUser' => CurrentUser::get(),
        ]);
    }

    public function update(Request $request, string $slug, WorkflowParser $parser): RedirectResponse
    {
        $path = $this->workflows->sharedFilePath($slug) ?? abort(404);
        $request->validate([
            'yaml' => ['required', 'string', 'max:20000'],
            'hash' => ['required', 'string'],
        ]);

        if (! hash_equals(sha1((string) file_get_contents($path)), $request->string('hash')->toString())) {
            throw ValidationException::withMessages(['yaml' => [
                'This file was changed by someone else since you opened it. Copy your changes, reload the page and try again.',
            ]]);
        }

        $yaml = rtrim(str_replace("\r\n", "\n", $request->string('yaml')->toString()))."\n";

        try {
            $workflow = $parser->parse($yaml, Workflow::SHARED, Workflow::SHARED.':'.$slug);
        } catch (InvalidWorkflow $invalid) {
            throw ValidationException::withMessages(['yaml' => $invalid->errors]);
        }

        if (! is_writable($path) || file_put_contents($path, $yaml, LOCK_EX) === false) {
            throw ValidationException::withMessages(['yaml' => ["Couldn't save actions/".basename($path).'. Check that the app may write to the actions folder.']]);
        }

        return redirect()
            ->route('actions.shared.show', $slug)
            ->with('status', "“{$workflow->name}” saved to actions/".basename($path).'.');
    }
}
