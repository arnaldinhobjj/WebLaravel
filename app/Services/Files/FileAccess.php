<?php

namespace App\Services\Files;

use App\Models\Folder;
use App\Models\FolderAccess;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who may do what with folders and files.
 *
 * - Personal areas: only their owner, with the "My files" permissions.
 * - Library: the "Files (library)" permission AND the top-level folder's
 *   rules (if it has any) must allow it for one of the person's tags or
 *   their department. Subfolders follow their top-level folder.
 *
 * Abilities: view, upload, edit (rename, move, new versions), delete.
 */
class FileAccess
{
    private const PAGE_ABILITY = ['view' => 'view', 'upload' => 'create', 'edit' => 'edit', 'delete' => 'delete'];

    public function allows(User $person, Folder $folder, string $ability): bool
    {
        $pageAbility = self::PAGE_ABILITY[$ability];

        if ($folder->isPersonal()) {
            return $folder->owner_id === $person->id && $person->hasPermission("my_files.{$pageAbility}");
        }

        if (! $person->hasPermission("files.{$pageAbility}")) {
            return false;
        }

        return $this->rulesAllow($person, $folder->topLevel(), $ability);
    }

    /**
     * Whether a top-level library folder's rules let this person do something.
     */
    public function rulesAllow(User $person, Folder $topLevel, string $ability): bool
    {
        if ($topLevel->isRoot()) {
            return true;
        }

        $rules = $topLevel->relationLoaded('access') ? $topLevel->access : $topLevel->access()->get();
        if ($rules->isEmpty()) {
            return true;
        }

        $tagIds = $person->allTags()->modelKeys();

        return $rules->contains(fn (FolderAccess $rule): bool => in_array($ability, $rule->abilities ?? [], true) && (
            ($rule->tag_id !== null && (in_array($rule->tag_id, $tagIds, true) || $rule->tag?->is_everyone))
            || ($rule->department_id !== null && $rule->department_id === $person->department_id)
        ));
    }

    /**
     * Ids of every library folder (not in the trash) this person may view,
     * for search.
     *
     * @return list<int>
     */
    public function visibleLibraryFolderIds(User $person): array
    {
        if (! $person->hasPermission('files.view')) {
            return [];
        }

        $root = Folder::libraryRoot();
        $all = Folder::whereNull('owner_id')->with('access.tag')->get(['id', 'parent_id', 'name', 'owner_id', 'deleted_at']);
        $children = $all->groupBy('parent_id');

        $visible = [$root->id];
        foreach ($children->get($root->id, collect()) as $topLevel) {
            if ($this->rulesAllow($person, $topLevel, 'view')) {
                array_push($visible, ...$this->descendantIds($topLevel->id, $children));
            }
        }

        return $visible;
    }

    /**
     * @param  Collection<int|string, Collection<int, Folder>>  $children
     * @return list<int>
     */
    private function descendantIds(int $folderId, Collection $children): array
    {
        $ids = [$folderId];
        foreach ($children->get($folderId, collect()) as $child) {
            array_push($ids, ...$this->descendantIds($child->id, $children));
        }

        return $ids;
    }
}
