<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillFailed;
use App\Ai\Skills\SkillResult;
use App\Models\User;
use App\Services\Files\FileAccess;
use App\Services\Files\FileStore;

class FilesTrash extends Skill
{
    public function name(): string
    {
        return 'files.trash';
    }

    public function title(): string
    {
        return 'Move a file to the trash';
    }

    public function description(): string
    {
        return 'Move a file to the trash by id. It can be restored for '.FileStore::TRASH_DAYS.' days. Always confirm with the person first.';
    }

    public function properties(): array
    {
        return ['file' => ['type' => 'integer']];
    }

    public function required(): array
    {
        return ['file'];
    }

    public function allows(User $me): bool
    {
        return $me->hasPermission('files.delete') || $me->hasPermission('my_files.delete');
    }

    public function changesThings(): bool
    {
        return true;
    }

    public function run(User $me, array $input): SkillResult
    {
        $file = FilesRead::readable($me, (int) $input['file']);
        if (! app(FileAccess::class)->allows($me, $file->folder, 'delete')) {
            throw new SkillFailed("You can't delete files in {$file->folder->path()}.");
        }

        $file->delete();

        return SkillResult::ok("“{$file->name}” moved to the trash.", ['id' => $file->id]);
    }
}
