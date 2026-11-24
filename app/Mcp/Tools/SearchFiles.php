<?php

namespace App\Mcp\Tools;

use App\Models\Folder;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileAccess;
use App\Services\Files\FileStore;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('search_files')]
#[Description('Finds files by name in the library folders the person may open and in their own files. Returns each file\'s id and folder path; use read_file to read a text file.')]
class SearchFiles extends FortValeTool
{
    protected function permissions(): array
    {
        return [];
    }

    public function shouldRegister(): bool
    {
        $me = auth()->user();

        return $me instanceof User && ($me->hasPermission('files.view') || $me->hasPermission('my_files.view'));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['search' => $schema->string()->description('Part of the file name.')->required()];
    }

    protected function perform(User $me, Request $request): Response
    {
        $term = mb_strtolower(trim((string) $request->validate(['search' => ['required', 'string', 'max:200']])['search']));

        $folderIds = app(FileAccess::class)->visibleLibraryFolderIds($me);
        if ($me->hasPermission('my_files.view')) {
            array_push($folderIds, ...app(FileStore::class)->subtreeIds(Folder::personalRootFor($me)));
        }

        $files = StoredFile::whereIn('folder_id', $folderIds)->whereNotNull('current_version_id')
            ->whereRaw('lower(name) like ?', ['%'.$term.'%'])
            ->with('folder')->latest('updated_at')->limit(50)->get()
            ->map(fn (StoredFile $file): array => [
                'id' => $file->id,
                'name' => $file->name,
                'folder' => $file->folder->path(),
                'size' => $file->size,
                'type' => $file->mime,
            ])->all();

        return Response::json(['files' => $files, 'count' => count($files)]);
    }
}
