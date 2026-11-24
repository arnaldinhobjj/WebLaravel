<?php

namespace App\Services\Files;

use App\Models\AppSetting;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stores file content in S3 (the `files` disk) and keeps names, folders and
 * versions in the database. Checks size, type and quota limits on upload.
 */
class FileStore
{
    public const DISK = 'files';

    public const TRASH_DAYS = 30;

    /** Shown in the browser instead of downloaded. Everything else downloads. */
    public const PREVIEWABLE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'];

    /** Shown as plain text in the preview (first 200 KB). */
    public const TEXT = ['text/plain', 'text/csv', 'text/markdown', 'text/x-log', 'application/json', 'application/xml', 'text/xml'];

    /** Programs, refused whatever their name says. */
    private const EXECUTABLE_TYPES = [
        'application/x-dosexec', 'application/x-msdownload', 'application/vnd.microsoft.portable-executable',
        'application/x-executable', 'application/x-elf', 'application/x-sharedlib', 'application/x-mach-binary',
        'application/x-msi', 'application/x-sh', 'text/x-shellscript',
    ];

    public const DEFAULTS = [
        'files.max_mb' => 25,
        'files.blocked_extensions' => ['exe', 'bat', 'cmd', 'sh', 'js', 'msi', 'com', 'scr', 'ps1'],
        'files.personal_quota_mb' => 1024,
        'files.total_quota_mb' => 0,
    ];

    public static function setting(string $key): mixed
    {
        return AppSetting::get($key, self::DEFAULTS[$key]);
    }

    /**
     * Stores an upload in a folder. A file with the same name there gets a new
     * version instead of a duplicate.
     *
     * @return array{file: StoredFile, newVersion: bool}
     */
    public function upload(User $person, Folder $folder, UploadedFile $upload): array
    {
        ['name' => $name, 'mime' => $mime, 'size' => $size] = $this->assertAcceptable($upload, $folder);

        $existing = StoredFile::where('folder_id', $folder->id)->where('name', $name)->first();

        return DB::connection(config('fortvale.connections.people'))->transaction(function () use ($person, $folder, $upload, $name, $mime, $size, $existing) {
            $file = $existing ?? StoredFile::create(['folder_id' => $folder->id, 'name' => $name, 'created_by' => $person->id]);
            $this->addVersion($file, $upload->getRealPath(), $mime, $size, $person);

            return ['file' => $file->refresh(), 'newVersion' => $existing !== null];
        });
    }

    /**
     * Attaches an upload to a page (see App\Support\Attachables). Attachments
     * have no versions; the same name twice gives two files.
     */
    public function attach(User $person, string $type, string $id, UploadedFile $upload): StoredFile
    {
        ['mime' => $mime, 'size' => $size, 'name' => $name] = $this->assertAcceptable($upload);

        return DB::connection(config('fortvale.connections.people'))->transaction(function () use ($person, $type, $id, $upload, $name, $mime, $size) {
            $file = new StoredFile(['name' => $name, 'created_by' => $person->id]);
            $file->attachable_type = $type;
            $file->attachable_id = $id;
            $file->save();
            $this->addVersion($file, $upload->getRealPath(), $mime, $size, $person);

            return $file->refresh();
        });
    }

    /**
     * Checks an upload against the limits before anything is stored, so a
     * form can refuse it before saving anything else.
     *
     * @return array{name: string, mime: string, size: int}
     */
    public function assertAcceptable(UploadedFile $upload, ?Folder $folder = null): array
    {
        $name = $this->cleanName($upload->getClientOriginalName());
        // Read the type from the content itself, never from the name or the browser.
        $mime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath()) ?: 'application/octet-stream');
        $size = (int) $upload->getSize();

        $this->checkLimits($name, $mime, $size, $folder);

        return ['name' => $name, 'mime' => $mime, 'size' => $size];
    }

    /**
     * Makes an old version the newest again, as a new version, so nothing is lost.
     */
    public function restoreVersion(StoredFile $file, FileVersion $version, User $person): FileVersion
    {
        $this->checkQuota($file->folder, $version->size);

        $path = $this->newPath();
        Storage::disk(self::DISK)->copy($version->path, $path);

        return $this->recordVersion($file, $path, $version->mime, $version->size, $version->sha256, $person);
    }

    public function rename(StoredFile|Folder $item, string $name): void
    {
        $name = $this->cleanName($name);
        if ($item instanceof StoredFile) {
            $this->checkExtension($name, 'name');
        }
        $siblings = $item instanceof StoredFile
            ? StoredFile::where('folder_id', $item->folder_id)
            : Folder::where('parent_id', $item->parent_id);

        if ($siblings->where('name', $name)->whereKeyNot($item->getKey())->exists()) {
            throw ValidationException::withMessages(['name' => "Something called “{$name}” is already there."]);
        }

        $item->update(['name' => $name]);
    }

    public function move(StoredFile $file, Folder $destination): void
    {
        if (StoredFile::where('folder_id', $destination->id)->where('name', $file->name)->whereKeyNot($file->id)->exists()) {
            throw ValidationException::withMessages(['folder_id' => "“{$destination->name}” already has a file called “{$file->name}”."]);
        }
        if ($file->folder->isPersonal() !== $destination->isPersonal()) {
            $this->checkQuota($destination, (int) $file->versions()->sum('size'));
        }

        $file->update(['folder_id' => $destination->id]);
    }

    public function createFolder(User $person, Folder $parent, string $name): Folder
    {
        $name = $this->cleanName($name);
        if (Folder::where('parent_id', $parent->id)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => "There’s already a folder called “{$name}” here."]);
        }

        return Folder::create(['parent_id' => $parent->id, 'name' => $name, 'owner_id' => $parent->owner_id, 'created_by' => $person->id]);
    }

    /**
     * Sends a version to the browser. Only safe types are shown inline;
     * everything else (including SVG and HTML) downloads.
     */
    public function stream(StoredFile $file, FileVersion $version, bool $inline): StreamedResponse
    {
        $inline = $inline && in_array($version->mime, self::PREVIEWABLE, true);
        $type = $inline ? $version->mime : 'application/octet-stream';

        $response = Storage::disk(self::DISK)->response($version->path, $file->name, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');

        return $response;
    }

    /**
     * The start of a text file, for the preview.
     */
    public function textPreview(FileVersion $version, int $bytes = 200_000): string
    {
        $stream = Storage::disk(self::DISK)->readStream($version->path);
        $text = (string) stream_get_contents($stream, $bytes);
        fclose($stream);

        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    /**
     * Permanently deletes trashed files and folders older than the trash
     * period (or all trashed items under a root, for "Empty trash"), with
     * their stored objects. Returns how many files were removed.
     */
    public function purge(?int $olderThanDays = self::TRASH_DAYS, ?Folder $root = null): int
    {
        $cutoff = $olderThanDays === null ? now() : now()->subDays($olderThanDays);
        $inArea = fn ($query) => $root === null ? $query : $query->whereIn('folder_id', $this->subtreeIds($root, true));

        $removed = 0;
        foreach ($inArea(StoredFile::onlyTrashed()->where('deleted_at', '<=', $cutoff))->get() as $file) {
            $removed += $this->forceDeleteFile($file);
        }

        $folders = Folder::onlyTrashed()->where('deleted_at', '<=', $cutoff)
            ->when($root, fn ($query) => $query->whereIn('id', $this->subtreeIds($root, true)))
            ->get();
        foreach ($folders as $folder) {
            foreach ($this->subtreeIds($folder, true) as $folderId) {
                foreach (StoredFile::withTrashed()->where('folder_id', $folderId)->get() as $file) {
                    $removed += $this->forceDeleteFile($file);
                }
            }
            Folder::withTrashed()->whereKey($folder->id)->first()?->forceDelete();
        }

        return $removed;
    }

    /**
     * Permanently deletes these trashed folders (with everything in them) and
     * files, including their stored content. Returns how many files went.
     *
     * @param  list<Folder>  $folders
     * @param  list<StoredFile>  $files
     */
    public function purgeItems(array $folders, array $files): int
    {
        $removed = 0;
        foreach ($files as $file) {
            $removed += $this->forceDeleteFile($file);
        }
        foreach ($folders as $folder) {
            foreach ($this->subtreeIds($folder, true) as $folderId) {
                foreach (StoredFile::withTrashed()->where('folder_id', $folderId)->get() as $file) {
                    $removed += $this->forceDeleteFile($file);
                }
            }
            Folder::withTrashed()->whereKey($folder->id)->first()?->forceDelete();
        }

        return $removed;
    }

    /**
     * Bytes used: by one person's private area, or by everything.
     */
    public function usage(?User $owner = null): int
    {
        $query = FileVersion::query()->join('files', 'files.id', '=', 'file_versions.file_id');

        if ($owner !== null) {
            $query->whereIn('files.folder_id', Folder::withTrashed()->where('owner_id', $owner->id)->select('id'));
        }

        return (int) $query->sum('file_versions.size');
    }

    /**
     * @return list<int>
     */
    public function subtreeIds(Folder $folder, bool $withTrashed = false): array
    {
        $ids = [$folder->id];
        $frontier = [$folder->id];
        while ($frontier !== []) {
            $frontier = Folder::withTrashed($withTrashed)->whereIn('parent_id', $frontier)->pluck('id')->all();
            array_push($ids, ...$frontier);
        }

        return $ids;
    }

    private function addVersion(StoredFile $file, string $localPath, string $mime, int $size, User $person): FileVersion
    {
        $path = $this->newPath();
        $stream = fopen($localPath, 'r');
        Storage::disk(self::DISK)->put($path, $stream, [
            'ContentType' => 'application/octet-stream',
            'ContentDisposition' => 'attachment',
        ]);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $this->recordVersion($file, $path, $mime, $size, (string) hash_file('sha256', $localPath), $person);
    }

    private function recordVersion(StoredFile $file, string $path, string $mime, int $size, string $sha256, User $person): FileVersion
    {
        $version = FileVersion::create([
            'file_id' => $file->id,
            'number' => (int) FileVersion::where('file_id', $file->id)->max('number') + 1,
            'path' => $path,
            'size' => $size,
            'mime' => $mime,
            'sha256' => $sha256,
            'uploaded_by' => $person->id,
        ]);

        $file->update(['current_version_id' => $version->id, 'size' => $size, 'mime' => $mime]);
        $file->touch();

        return $version;
    }

    private function forceDeleteFile(StoredFile $file): int
    {
        $paths = FileVersion::where('file_id', $file->id)->pluck('path')->all();
        if ($paths !== []) {
            Storage::disk(self::DISK)->delete($paths);
        }
        $file->forceDelete();

        return 1;
    }

    private function checkExtension(string $name, string $errorKey = 'files'): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $blocked = array_map('strtolower', (array) self::setting('files.blocked_extensions'));

        if ($extension !== '' && in_array($extension, $blocked, true)) {
            throw ValidationException::withMessages([$errorKey => "“{$name}”: .{$extension} files aren’t allowed."]);
        }
    }

    private function checkLimits(string $name, string $mime, int $size, ?Folder $folder): void
    {
        $this->checkExtension($name);
        if (in_array($mime, self::EXECUTABLE_TYPES, true)) {
            throw ValidationException::withMessages(['files' => "“{$name}” is a program, which isn’t allowed, whatever its name says."]);
        }

        $maxBytes = (int) self::setting('files.max_mb') * 1024 * 1024;
        if ($size > $maxBytes) {
            throw ValidationException::withMessages(['files' => "“{$name}” is larger than the ".self::setting('files.max_mb').' MB limit.']);
        }

        $this->checkQuota($folder, $size);
    }

    private function checkQuota(?Folder $folder, int $extraBytes): void
    {
        $total = (int) self::setting('files.total_quota_mb') * 1024 * 1024;
        if ($total > 0 && $this->usage() + $extraBytes > $total) {
            throw ValidationException::withMessages(['files' => 'Fort Vale’s file storage is full. Ask an administrator to free space or raise the limit.']);
        }

        if ($folder?->isPersonal()) {
            $quota = (int) self::setting('files.personal_quota_mb') * 1024 * 1024;
            $owner = User::find($folder->owner_id);
            if ($quota > 0 && $owner !== null && $this->usage($owner) + $extraBytes > $quota) {
                throw ValidationException::withMessages(['files' => 'This would go over your '.self::setting('files.personal_quota_mb').' MB limit for My files. Empty your trash or delete old files.']);
            }
        }
    }

    /**
     * A file or folder name without path parts or control characters.
     */
    private function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', basename(str_replace('\\', '/', $name))));
        $name = Str::limit($name, 120, '');

        if ($name === '' || $name === '.' || $name === '..') {
            throw ValidationException::withMessages(['name' => 'Please give it a name.']);
        }

        return $name;
    }

    /** A random object key, e.g. "3f/9a/3f9a…". */
    private function newPath(): string
    {
        $uuid = (string) Str::uuid();

        return substr($uuid, 0, 2).'/'.substr($uuid, 2, 2).'/'.$uuid;
    }
}
