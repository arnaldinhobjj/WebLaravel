<?php

namespace App\Mcp\Tools;

use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FilePaths;
use App\Services\Files\FileStore;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('read_file')]
#[Description('Reads a text file (txt, csv, md, json, xml, logs…) the person may open, by its id from search_files. Returns at most the first 200 KB. Other kinds of file can\'t be read.')]
class ReadFile extends FortValeTool
{
    protected function permissions(): array
    {
        return [];
    }

    public function shouldRegister(): bool
    {
        return (new SearchFiles)->shouldRegister();
    }

    public function schema(JsonSchema $schema): array
    {
        return ['file' => $schema->integer()->description('The file id.')->required()];
    }

    protected function perform(User $me, Request $request): Response
    {
        $id = (int) $request->validate(['file' => ['required', 'integer']])['file'];
        $file = StoredFile::with('currentVersion', 'folder')->find($id);

        if ($file === null || ! app(FilePaths::class)->canRead($me, $file)) {
            return Response::error("File {$id} wasn't found.");
        }

        $mime = $file->currentVersion->mime;
        if (! in_array($mime, FileStore::TEXT, true) && ! (str_starts_with($mime, 'text/') && $mime !== 'text/html')) {
            return Response::error("“{$file->name}” isn't a text file ({$mime}), so it can't be read here.");
        }

        return Response::text(app(FileStore::class)->textPreview($file->currentVersion));
    }
}
