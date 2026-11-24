<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillFailed;
use App\Ai\Skills\SkillResult;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FilePaths;
use App\Services\Files\FileStore;

class FilesRead extends Skill
{
    public function name(): string
    {
        return 'files.read';
    }

    public function title(): string
    {
        return 'Read a text file';
    }

    public function description(): string
    {
        return 'Read a text file (txt, csv, md, json, xml, logs…) by its id. Gives at most the first 200 KB; other kinds of file can\'t be read.';
    }

    public function properties(): array
    {
        return ['file' => ['type' => 'integer', 'description' => 'The file id (from files.search or files.list_folder).']];
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
        $file = self::readable($me, (int) $input['file']);
        $mime = $file->currentVersion->mime;
        if (! in_array($mime, FileStore::TEXT, true) && ! (str_starts_with($mime, 'text/') && $mime !== 'text/html')) {
            throw new SkillFailed("“{$file->name}” isn't a text file ({$mime}), so it can't be read here.");
        }

        return SkillResult::ok("Read {$file->name}.", ['name' => $file->name, 'text' => app(FileStore::class)->textPreview($file->currentVersion)]);
    }

    public static function readable(User $me, int $id): StoredFile
    {
        $file = StoredFile::with(['currentVersion', 'folder'])->find($id);
        if ($file === null || ! app(FilePaths::class)->canRead($me, $file)) {
            throw new SkillFailed("File {$id} wasn't found.");
        }

        return $file;
    }
}
