<?php

namespace App\Models;

use Database\Factories\FolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A folder. The library has one root folder (no parent, no owner); each person
 * has a private root (no parent, `owner_id` set), and every folder below a
 * private root carries the same `owner_id`.
 */
#[Fillable(['parent_id', 'name', 'owner_id', 'created_by'])]
class Folder extends Model
{
    /** @use HasFactory<FolderFactory> */
    use HasFactory, SoftDeletes;

    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.people');
    }

    public static function libraryRoot(): self
    {
        return static::firstOrCreate(['parent_id' => null, 'owner_id' => null], ['name' => 'Library']);
    }

    public static function personalRootFor(User $person): self
    {
        return static::firstOrCreate(['parent_id' => null, 'owner_id' => $person->id], ['name' => 'My files', 'created_by' => $person->id]);
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function isPersonal(): bool
    {
        return $this->owner_id !== null;
    }

    /**
     * Folders from the root down to this one.
     *
     * @return list<Folder>
     */
    public function ancestry(): array
    {
        $chain = [$this];
        $seen = [$this->id => true];
        $folder = $this;
        // No depth limit: access rules live on the top-level folder, so the walk must reach it.
        while ($folder->parent_id !== null) {
            if (isset($seen[$folder->parent_id])) {
                throw new \RuntimeException("Folder {$this->id} is inside a loop of folders.");
            }
            $folder = static::withTrashed()->find($folder->parent_id);
            if ($folder === null) {
                throw new \RuntimeException("Folder {$this->id} has a missing parent folder.");
            }
            $seen[$folder->id] = true;
            array_unshift($chain, $folder);
        }

        return $chain;
    }

    /**
     * The library folder whose access rules apply: the folder just below the
     * root (or the root itself).
     */
    public function topLevel(): self
    {
        return $this->ancestry()[1] ?? $this;
    }

    /** "Library / Quality / Audits" */
    public function path(): string
    {
        return implode(' / ', array_map(fn (Folder $folder): string => $folder->name, $this->ancestry()));
    }

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id')->withTrashed();
    }

    /**
     * @return HasMany<Folder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id')->orderBy('name');
    }

    /**
     * @return HasMany<StoredFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(StoredFile::class)->orderBy('name');
    }

    /**
     * @return HasMany<FolderAccess, $this>
     */
    public function access(): HasMany
    {
        return $this->hasMany(FolderAccess::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
