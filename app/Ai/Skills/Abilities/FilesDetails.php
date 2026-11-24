<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Present;
use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\User;

class FilesDetails extends Skill
{
    public function name(): string
    {
        return 'files.details';
    }

    public function title(): string
    {
        return 'File details and versions';
    }

    public function description(): string
    {
        return 'Details of a file by id: folder, size, type, and every version with who uploaded it and when.';
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
        return FilesSearch::canBrowse($me);
    }

    public function run(User $me, array $input): SkillResult
    {
        $file = FilesRead::readable($me, (int) $input['file'])->load('versions.uploader');

        return SkillResult::ok("{$file->name}: ".$file->versions->count().' versions.', Present::file($file) + [
            'versions' => $file->versions->map(fn ($version): array => [
                'number' => $version->number,
                'size' => $version->size,
                'by' => $version->uploader?->name,
                'at' => $version->created_at->toIso8601String(),
            ])->all(),
        ]);
    }
}
