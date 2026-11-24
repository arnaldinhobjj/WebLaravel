<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Present;
use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillFailed;
use App\Ai\Skills\SkillResult;
use App\Models\Folder;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileAccess;
use App\Services\Files\FilePaths;

class FilesListFolder extends Skill
{
    public function name(): string
    {
        return 'files.list_folder';
    }

    public function title(): string
    {
        return 'List a folder';
    }

    public function description(): string
    {
        return 'The folders and files inside a folder, by path: "Library", "Library/Quality/Audits", "My files" or "My files/Receipts".';
    }

    public function properties(): array
    {
        return ['path' => ['type' => 'string', 'maxLength' => 500, 'description' => 'Folder path; default "Library".']];
    }

    public function allows(User $me): bool
    {
        return FilesSearch::canBrowse($me);
    }

    public function run(User $me, array $input): SkillResult
    {
        $folder = self::openable($me, (string) ($input['path'] ?? 'Library'));
        $access = app(FileAccess::class);

        $folders = $folder->children()->get()
            ->filter(fn (Folder $child) => $access->allows($me, $child, 'view'))
            ->map(fn (Folder $child): array => ['name' => $child->name, 'path' => self::pathOf($child)])
            ->values()->all();
        $files = $folder->files()->whereNotNull('current_version_id')->with('folder')->get()
            ->map(fn (StoredFile $file) => Present::file($file))->all();

        return SkillResult::ok(count($folders).' folders and '.count($files)." files in {$folder->path()}.", [
            'path' => self::pathOf($folder),
            'folders' => $folders,
            'files' => $files,
        ]);
    }

    /**
     * A folder by path that the person may open, or a "not found" failure.
     */
    public static function openable(User $me, string $path): Folder
    {
        $folder = app(FilePaths::class)->folder($path, $me);
        if ($folder === null || ! app(FileAccess::class)->allows($me, $folder, 'view')) {
            throw new SkillFailed("No folder “{$path}” that you can open. Paths start with “Library” or “My files”.");
        }

        return $folder;
    }

    /** The path skills accept: "Library/…" or "My files/…". */
    public static function pathOf(Folder $folder): string
    {
        $names = array_map(fn (Folder $step) => $step->name, $folder->ancestry());
        $names[0] = $folder->isPersonal() ? FilePaths::MINE : FilePaths::LIBRARY;

        return implode('/', $names);
    }
}
