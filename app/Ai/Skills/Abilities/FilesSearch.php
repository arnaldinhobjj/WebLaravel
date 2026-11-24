<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Present;
use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\Folder;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileAccess;
use App\Services\Files\FileStore;

class FilesSearch extends Skill
{
    public function name(): string
    {
        return 'files.search';
    }

    public function title(): string
    {
        return 'Search files';
    }

    public function description(): string
    {
        return 'Find files by name in the library folders the person may open and in their own files. Gives each file\'s id and folder.';
    }

    public function properties(): array
    {
        return ['search' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Part of the file name.']];
    }

    public function required(): array
    {
        return ['search'];
    }

    public function allows(User $me): bool
    {
        return self::canBrowse($me);
    }

    public function run(User $me, array $input): SkillResult
    {
        $files = StoredFile::whereIn('folder_id', self::visibleFolderIds($me))->whereNotNull('current_version_id')
            ->whereRaw('lower(name) like ?', ['%'.mb_strtolower(trim($input['search'])).'%'])
            ->with('folder')->latest('updated_at')->limit(50)->get()
            ->map(fn (StoredFile $file) => Present::file($file))->all();

        return SkillResult::ok(count($files).' '.str('file')->plural(count($files)).' found.', ['files' => $files]);
    }

    public static function canBrowse(User $me): bool
    {
        return $me->hasPermission('files.view') || $me->hasPermission('my_files.view');
    }

    /**
     * Library folders the person may open, plus their own.
     *
     * @return list<int>
     */
    public static function visibleFolderIds(User $me): array
    {
        $ids = app(FileAccess::class)->visibleLibraryFolderIds($me);
        if ($me->hasPermission('my_files.view')) {
            array_push($ids, ...app(FileStore::class)->subtreeIds(Folder::personalRootFor($me)));
        }

        return $ids;
    }
}
