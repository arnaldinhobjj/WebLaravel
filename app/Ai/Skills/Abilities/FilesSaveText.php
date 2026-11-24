<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillFailed;
use App\Ai\Skills\SkillResult;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileAccess;
use App\Services\Files\FileStore;
use Illuminate\Http\UploadedFile;

class FilesSaveText extends Skill
{
    public const MAX_BYTES = 1024 * 1024;

    public function name(): string
    {
        return 'files.save_text';
    }

    public function title(): string
    {
        return 'Save a text file';
    }

    public function description(): string
    {
        return 'Save text (notes, a report, CSV…) as a file in a folder. If a file with that name is there, this becomes its new version; old versions are kept.';
    }

    public function properties(): array
    {
        return [
            'folder' => ['type' => 'string', 'maxLength' => 500, 'description' => 'e.g. "Library/Reports" or "My files".'],
            'name' => ['type' => 'string', 'maxLength' => 120, 'description' => 'File name with extension, e.g. "weekly.md".'],
            'content' => ['type' => 'string', 'maxLength' => self::MAX_BYTES],
        ];
    }

    public function required(): array
    {
        return ['folder', 'name', 'content'];
    }

    public function allows(User $me): bool
    {
        return $me->hasPermission('files.create') || $me->hasPermission('my_files.create');
    }

    public function changesThings(): bool
    {
        return true;
    }

    public function run(User $me, array $input): SkillResult
    {
        $folder = FilesListFolder::openable($me, $input['folder']);
        $exists = StoredFile::where('folder_id', $folder->id)->where('name', trim($input['name']))->exists();
        if (! app(FileAccess::class)->allows($me, $folder, $exists ? 'edit' : 'upload')) {
            throw new SkillFailed("You can't ".($exists ? 'change files' : 'add files')." in “{$input['folder']}”.");
        }

        $temporary = tempnam(sys_get_temp_dir(), 'fortvale-');
        file_put_contents($temporary, $input['content']);
        try {
            $result = app(FileStore::class)->upload($me, $folder, new UploadedFile($temporary, trim($input['name']), null, null, true));
        } finally {
            @unlink($temporary);
        }

        $file = $result['file'];

        return SkillResult::ok(($result['newVersion'] ? 'Saved a new version of ' : 'Saved ').$file->name.'.', [
            'id' => $file->id,
            'path' => FilesListFolder::pathOf($folder).'/'.$file->name,
            'version' => $file->currentVersion->number,
        ]);
    }
}
