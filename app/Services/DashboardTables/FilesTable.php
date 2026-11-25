<?php

namespace App\Services\DashboardTables;

use App\Models\Folder;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Files in the library (folders the viewer may open, with the Files
 * permission) and in the viewer's own My files (with the My files
 * permission). Never anyone else's personal files.
 */
class FilesTable extends TableSource
{
    public function __construct(private FileAccess $access) {}

    public function key(): string
    {
        return 'files';
    }

    public function label(): string
    {
        return 'Files';
    }

    public function description(): string
    {
        return 'Recently changed files in the library and your own files.';
    }

    public function isAvailableTo(User $person): bool
    {
        return $person->can('files.view') || $person->can('my_files.view');
    }

    public function columns(User $person): array
    {
        return [
            'name' => 'Name',
            'folder' => 'Folder',
            'size' => 'Size',
            'added_by' => 'Added by',
            'updated' => 'Changed',
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'folder', 'updated'];
    }

    public function filters(User $person): array
    {
        $places = [];
        if ($person->can('files.view')) {
            $places['library'] = 'The library';
        }
        if ($person->can('my_files.view')) {
            $places['mine'] = 'My files';
        }

        return [
            'place' => ['label' => 'Where', 'options' => ['' => 'Everywhere I can see'] + $places, 'default' => '', 'summary' => ['library' => 'Library', 'mine' => 'My files']],
            'changed_within' => [
                'label' => 'Changed',
                'options' => ['' => 'Any time', '7' => 'In the last 7 days', '30' => 'In the last 30 days', '90' => 'In the last 90 days'],
                'default' => '',
                'summary' => ['7' => 'Changed in 7 days', '30' => 'Changed in 30 days', '90' => 'Changed in 90 days'],
            ],
        ];
    }

    protected function query(User $viewer, array $filters): Builder
    {
        $place = $filters['place'] ?? '';
        $folderIds = [];
        if ($place !== 'mine') {
            array_push($folderIds, ...$this->access->visibleLibraryFolderIds($viewer));
        }
        if ($place !== 'library' && $viewer->can('my_files.view')) {
            $mine = Folder::whereNull('parent_id')->where('owner_id', $viewer->id)->first();
            if ($mine) {
                array_push($folderIds, ...$mine->subtreeIds());
            }
        }

        $days = (int) ($filters['changed_within'] ?? 0);

        return StoredFile::query()
            ->whereIn('folder_id', $folderIds === [] ? [0] : $folderIds)
            ->when($days > 0, fn (Builder $query) => $query->where('updated_at', '>=', now()->subDays($days)))
            ->with(['folder', 'creator'])
            ->latest('updated_at')
            ->latest('id');
    }

    /**
     * @param  StoredFile  $row
     */
    protected function cell(Model $row, string $column): string
    {
        return match ($column) {
            'name' => $row->name,
            'folder' => $row->folder?->isRoot() ? ($row->folder->isPersonal() ? 'My files' : 'Library') : ($row->folder?->name ?? '—'),
            'size' => $this->readableSize((int) $row->size),
            'added_by' => $row->creator?->name ?? '—',
            'updated' => $row->updated_at->diffForHumans(),
            default => '',
        };
    }

    /**
     * @param  StoredFile  $row
     */
    protected function rowUrl(Model $row, User $viewer): ?string
    {
        return route('files.preview', $row);
    }

    protected function listUrl(User $viewer, array $filters): ?string
    {
        return match ($filters['place'] ?? '') {
            'library' => route('files.library'),
            'mine' => route('files.mine'),
            default => route('workspace.index'),
        };
    }

    private function readableSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) $bytes : number_format($size, 1)).' '.$units[$unit];
    }
}
