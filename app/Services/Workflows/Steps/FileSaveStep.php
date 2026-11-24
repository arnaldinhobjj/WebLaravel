<?php

namespace App\Services\Workflows\Steps;

use App\Models\StoredFile;
use App\Services\Files\FileAccess;
use App\Services\Files\FilePaths;
use App\Services\Files\FileStore;
use App\Services\Workflows\Expressions;
use App\Services\Workflows\RunContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * `file/save`: saves text (a report, a web response…) as a file in a folder,
 * e.g. `folder: Library/Reports`, `name: weekly.txt`. A file with that name
 * gets a new version. Needs upload (new file) or edit (new version) access
 * in the folder for whose files the action uses (see RunContext::filesPerson).
 */
class FileSaveStep extends BaseStep
{
    public const MAX_BYTES = 1024 * 1024;

    public function __construct(private FilePaths $paths, private FileAccess $access, private FileStore $store) {}

    protected function keys(): array
    {
        return ['folder' => true, 'name' => true, 'content' => true, 'as' => false];
    }

    protected function checks(array $with, string $path): array
    {
        $errors = [
            ...$this->plainText($with, 'folder', $path, 500),
            ...$this->plainText($with, 'name', $path, 120),
            ...$this->plainText($with, 'content', $path, self::MAX_BYTES),
        ];

        $folder = $with['folder'] ?? null;
        if (is_string($folder) && ! Expressions::hasPlaceholder($folder) && ! preg_match('#^(Library|My files)(/|$)#', $folder)) {
            $errors[] = "{$path}.folder: must start with \"Library\" or \"My files\", e.g. Library/Reports.";
        }

        return $errors;
    }

    public function run(array $with, RunContext $context): array
    {
        $person = $context->filesPerson($with['as'] ?? null)
            ?? throw new StepFailed('Saving a file needs a person: add `as: <username>` to the step.');

        $folder = $this->paths->folder((string) $with['folder'], $person)
            ?? throw new StepFailed("No folder \"{$with['folder']}\".");

        $name = trim((string) $with['name']);
        $content = (string) $with['content'];
        if (strlen($content) > self::MAX_BYTES) {
            throw new StepFailed('The content is larger than 1 MB.');
        }

        $exists = StoredFile::where('folder_id', $folder->id)->where('name', $name)->exists();
        if (! $this->access->allows($person, $folder, 'view') || ! $this->access->allows($person, $folder, $exists ? 'edit' : 'upload')) {
            throw new StepFailed("{$person->name} may not ".($exists ? 'change files' : 'add files')." in \"{$with['folder']}\".");
        }

        $temporary = tempnam(sys_get_temp_dir(), 'fortvale-');
        file_put_contents($temporary, $content);

        try {
            $result = $this->store->upload($person, $folder, new UploadedFile($temporary, $name, null, null, true));
        } catch (ValidationException $refused) {
            throw new StepFailed($refused->getMessage());
        } finally {
            @unlink($temporary);
        }

        return ['file' => $folder->path().' / '.$result['file']->name, 'version' => $result['file']->currentVersion->number];
    }
}
