<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Present;
use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\StoredFile;
use App\Models\User;

class FilesRecent extends Skill
{
    public function name(): string
    {
        return 'files.recent';
    }

    public function title(): string
    {
        return 'Recently changed files';
    }

    public function description(): string
    {
        return 'The files the person may open that changed most recently.';
    }

    public function properties(): array
    {
        return ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'How many (default 15).']];
    }

    public function allows(User $me): bool
    {
        return FilesSearch::canBrowse($me);
    }

    public function run(User $me, array $input): SkillResult
    {
        $files = StoredFile::whereIn('folder_id', FilesSearch::visibleFolderIds($me))->whereNotNull('current_version_id')
            ->with('folder')->latest('updated_at')->limit((int) ($input['limit'] ?? 15))->get()
            ->map(fn (StoredFile $file) => Present::file($file))->all();

        return SkillResult::ok(count($files).' recent files.', ['files' => $files]);
    }
}
