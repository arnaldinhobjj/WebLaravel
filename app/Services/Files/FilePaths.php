<?php

namespace App\Services\Files;

use App\Models\Folder;
use App\Models\StoredFile;
use App\Models\User;

/**
 * Turns paths used in actions into folders and files:
 * "Library/Quality/Checklist.pdf" or "My files/Reports/weekly.txt" (the
 * person's own files). Things in the trash aren't found.
 */
class FilePaths
{
    public const LIBRARY = 'Library';

    public const MINE = 'My files';

    public function __construct(private FileAccess $access) {}

    public function folder(string $path, ?User $person): ?Folder
    {
        $parts = $this->parts($path);
        $folder = match ($parts[0] ?? null) {
            self::LIBRARY => Folder::libraryRoot(),
            self::MINE => $person ? Folder::personalRootFor($person) : null,
            default => null,
        };

        foreach (array_slice($parts, 1) as $name) {
            $folder = $folder?->children()->where('name', $name)->first();
        }

        return $folder;
    }

    public function file(string $path, ?User $person): ?StoredFile
    {
        $parts = $this->parts($path);
        if (count($parts) < 2) {
            return null;
        }

        $folder = $this->folder(implode('/', array_slice($parts, 0, -1)), $person);

        return $folder?->files()->where('name', end($parts))->first();
    }

    /**
     * Whether a person may read a file. With no person (a scheduled shared
     * action), only library folders without access rules can be read.
     */
    public function canRead(?User $person, StoredFile $file): bool
    {
        $folder = $file->folder;
        if ($folder === null || $file->currentVersion === null || $file->trashed()) {
            return false;
        }
        // Things in the trash (or inside a trashed folder) aren't readable, as on the Files pages.
        foreach ($folder->ancestry() as $step) {
            if ($step->trashed()) {
                return false;
            }
        }

        if ($person === null) {
            $topLevel = $folder->topLevel();

            return ! $folder->isPersonal() && ($topLevel->isRoot() || $topLevel->access()->doesntExist());
        }

        return $this->access->allows($person, $folder, 'view');
    }

    /**
     * @return list<string>
     */
    private function parts(string $path): array
    {
        return array_values(array_filter(array_map('trim', explode('/', $path)), fn (string $part): bool => $part !== ''));
    }
}
