<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillFailed;
use App\Ai\Skills\SkillResult;
use App\Models\User;
use App\Services\Files\FileAccess;
use App\Services\Files\FileStore;

class FilesCreateFolder extends Skill
{
    public function name(): string
    {
        return 'files.create_folder';
    }

    public function title(): string
    {
        return 'Create a folder';
    }

    public function description(): string
    {
        return 'Create a folder inside another, e.g. in "Library/Reports" or "My files".';
    }

    public function properties(): array
    {
        return [
            'in' => ['type' => 'string', 'maxLength' => 500, 'description' => 'Path of the folder to create it in.'],
            'name' => ['type' => 'string', 'maxLength' => 120],
        ];
    }

    public function required(): array
    {
        return ['in', 'name'];
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
        $parent = FilesListFolder::openable($me, $input['in']);
        if (! app(FileAccess::class)->allows($me, $parent, 'upload')) {
            throw new SkillFailed("You can't add folders in “{$input['in']}”.");
        }

        $folder = app(FileStore::class)->createFolder($me, $parent, $input['name']);

        return SkillResult::ok("Folder {$folder->name} created.", ['path' => FilesListFolder::pathOf($folder)]);
    }
}
