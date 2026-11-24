<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\FolderAccess;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\User;
use App\Services\Files\FileAccess;
use App\Services\Files\FileStore;
use App\Support\Attachables;
use App\Support\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The file library and each person's private files: browsing, uploads,
 * versions, preview, search and trash. Every request checks the folder's
 * rules (App\Services\Files\FileAccess); things you may not see answer 404.
 */
class FileController extends Controller
{
    public function __construct(private FileAccess $access, private FileStore $store) {}

    public function library(Request $request): View
    {
        return $this->browse($request, Folder::libraryRoot());
    }

    public function mine(Request $request): View
    {
        return $this->browse($request, Folder::personalRootFor(CurrentUser::get()));
    }

    public function show(Request $request, Folder $folder): View
    {
        return $this->browse($request, $folder);
    }

    /**
     * The workspace: files as icons, like the Finder. The top shows the
     * areas you may open (the library and your own files).
     */
    public function workspace(): View
    {
        $me = CurrentUser::get();

        $areas = array_values(array_filter([
            $me->hasPermission('files.view') ? Folder::libraryRoot() : null,
            $me->hasPermission('my_files.view') ? Folder::personalRootFor($me) : null,
        ]));

        return view('files.workspace', [
            'folder' => null,
            'ancestry' => [],
            'areas' => $areas,
            'newIn' => collect($areas)->filter(fn (Folder $area) => $this->access->allows($me, $area, 'upload'))->values(),
            'children' => collect(),
            'files' => collect(),
            'can' => collect(['upload' => false, 'edit' => false, 'delete' => false]),
        ]);
    }

    public function workspaceFolder(Request $request, Folder $folder): View
    {
        return $this->browse($request, $folder, 'files.workspace');
    }

    public function upload(Request $request, Folder $folder): RedirectResponse
    {
        $this->authorizeFolder($folder, 'upload');
        $request->validate(['files' => ['required', 'array', 'max:20'], 'files.*' => ['required', 'file']], [
            'files.*.uploaded' => 'A file couldn’t be uploaded; it may be larger than the server allows.',
        ]);

        $added = $versions = 0;
        foreach ($request->file('files') as $upload) {
            $result = $this->store->upload(CurrentUser::get(), $folder, $upload);
            $result['newVersion'] ? $versions++ : $added++;
        }

        $parts = array_filter([
            $added ? "{$added} ".str('file')->plural($added).' uploaded' : null,
            $versions ? "{$versions} new ".str('version')->plural($versions) : null,
        ]);

        return back()->with('status', ucfirst(implode(', ', $parts)).'.');
    }

    public function createFolder(Request $request, Folder $folder): RedirectResponse
    {
        $this->authorizeFolder($folder, 'upload');
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $created = $this->store->createFolder(CurrentUser::get(), $folder, $data['name']);

        $url = $request->input('from') === 'workspace' ? route('workspace.folder', $created) : route('files.folder', $created);

        return redirect()->to($url)->with('status', "Folder “{$created->name}” created.");
    }

    public function renameFolder(Request $request, Folder $folder): RedirectResponse
    {
        abort_if($folder->isRoot(), 404);
        $this->authorizeFolder($folder, 'edit');
        $this->store->rename($folder, $request->validate(['name' => ['required', 'string', 'max:120']])['name']);

        return back()->with('status', 'Folder renamed.');
    }

    public function destroyFolder(Request $request, Folder $folder): RedirectResponse
    {
        abort_if($folder->isRoot(), 404);
        $this->authorizeFolder($folder, 'delete');
        $parent = $folder->parent;
        $folder->delete();

        $url = $request->input('from') === 'workspace' ? route('workspace.folder', $parent) : $this->folderUrl($parent);

        return redirect()->to($url)->with('status', "“{$folder->name}” moved to the trash.");
    }

    public function editAccess(Folder $folder): View
    {
        $this->authorizeTopLevelAccess($folder);

        return view('files.access', [
            'folder' => $folder->load('access'),
            'tags' => Tag::orderByDesc('is_everyone')->orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function updateAccess(Request $request, Folder $folder): RedirectResponse
    {
        $this->authorizeTopLevelAccess($folder);
        $data = $request->validate([
            'tags' => ['array'],
            'tags.*' => ['array'],
            'tags.*.*' => [Rule::in(array_keys(FolderAccess::ABILITIES))],
            'departments' => ['array'],
            'departments.*' => ['array'],
            'departments.*.*' => [Rule::in(array_keys(FolderAccess::ABILITIES))],
        ]);

        DB::connection(config('fortvale.connections.people'))->transaction(function () use ($folder, $data) {
            $folder->access()->delete();
            foreach (['tags' => 'tag_id', 'departments' => 'department_id'] as $group => $column) {
                $model = $group === 'tags' ? Tag::class : Department::class;
                foreach ($data[$group] ?? [] as $id => $abilities) {
                    if ($abilities !== [] && $model::whereKey($id)->exists()) {
                        $folder->access()->create([$column => (int) $id, 'abilities' => $this->withView($abilities)]);
                    }
                }
            }
        });

        return redirect()->route('files.folder', $folder)->with('status', "Access to “{$folder->name}” saved.");
    }

    public function download(StoredFile $file): StreamedResponse
    {
        $this->authorizeFile($file, 'view');

        return $this->store->stream($file, $file->currentVersion, inline: false);
    }

    public function raw(StoredFile $file): StreamedResponse
    {
        $this->authorizeFile($file, 'view');

        return $this->store->stream($file, $file->currentVersion, inline: true);
    }

    public function preview(StoredFile $file): View
    {
        $this->authorizeFile($file, 'view');
        $version = $file->currentVersion;

        return view('files.preview', [
            'file' => $file,
            'location' => $this->locationOf($file),
            'kind' => match (true) {
                str_starts_with($version->mime, 'image/') && in_array($version->mime, FileStore::PREVIEWABLE, true) => 'image',
                $version->mime === 'application/pdf' => 'pdf',
                in_array($version->mime, FileStore::TEXT, true) || str_starts_with($version->mime, 'text/') && ! in_array($version->mime, ['text/html', 'text/javascript'], true) => 'text',
                default => 'none',
            },
            'text' => fn () => $this->store->textPreview($version),
        ]);
    }

    public function history(StoredFile $file): View
    {
        $this->authorizeFile($file, 'view');

        return view('files.history', [
            'file' => $file->load('versions.uploader'),
            'location' => $this->locationOf($file),
            'canEdit' => $file->folder !== null && $this->access->allows(CurrentUser::get(), $file->folder, 'edit'),
        ]);
    }

    public function downloadVersion(StoredFile $file, FileVersion $version): StreamedResponse
    {
        abort_unless($version->file_id === $file->id, 404);
        $this->authorizeFile($file, 'view');

        return $this->store->stream($file, $version, inline: false);
    }

    public function restoreVersion(StoredFile $file, FileVersion $version): RedirectResponse
    {
        abort_unless($version->file_id === $file->id, 404);
        $this->authorizeFile($file, 'edit');
        $new = $this->store->restoreVersion($file, $version, CurrentUser::get());

        return back()->with('status', "Version {$version->number} restored as version {$new->number}.");
    }

    public function rename(Request $request, StoredFile $file): RedirectResponse
    {
        $this->authorizeFile($file, 'edit');
        $this->store->rename($file, $request->validate(['name' => ['required', 'string', 'max:120']])['name']);

        return back()->with('status', 'File renamed.');
    }

    public function move(Request $request, StoredFile $file): RedirectResponse
    {
        $this->authorizeFile($file, 'edit');
        $destination = Folder::findOrFail($request->validate(['folder_id' => ['required', 'integer']])['folder_id']);
        $this->authorizeFolder($destination, 'upload');

        $this->store->move($file, $destination);

        return back()->with('status', "“{$file->name}” moved to {$destination->name}.");
    }

    public function destroy(StoredFile $file): RedirectResponse
    {
        $this->authorizeFile($file, 'delete');
        $file->delete();

        return back()->with('status', "“{$file->name}” moved to the trash.");
    }

    public function search(Request $request): View
    {
        $me = CurrentUser::get();
        $term = trim((string) $request->string('q'));
        $folderIds = $this->access->visibleLibraryFolderIds($me);
        if ($me->hasPermission('my_files.view')) {
            $mine = Folder::personalRootFor($me);
            array_push($folderIds, ...$this->visibleSubtree($mine));
        }

        $pattern = '%'.mb_strtolower($term).'%';
        $matches = fn ($query) => $query->whereRaw('lower(name) like ?', [$pattern]);

        return view('files.search', [
            'term' => $term,
            'folders' => $term === '' ? collect() : $matches(Folder::whereIn('id', $folderIds)->whereNotNull('parent_id'))->orderBy('name')->limit(50)->get(),
            'files' => $term === '' ? collect() : $matches(StoredFile::whereIn('folder_id', $folderIds))->with('folder')->orderBy('name')->limit(100)->get(),
        ]);
    }

    /**
     * The trash in the Files (Finder-style) view.
     */
    public function workspaceTrash(Request $request): View
    {
        return $this->trash($request, 'files.workspace-trash');
    }

    public function trash(Request $request, string $view = 'files.trash'): View
    {
        $me = CurrentUser::get();
        $mine = $request->query('area') === 'mine';
        abort_unless($mine ? $me->hasPermission('my_files.delete') : $me->hasPermission('files.delete'), 403);
        ['folders' => $folders, 'files' => $files] = $this->trashFor($me, $mine);

        return view($view, [
            'mine' => $mine,
            'folders' => $folders,
            'files' => $files,
            'canEmpty' => $mine ? $me->hasPermission('my_files.delete') : $me->hasPermission('files.empty_trash'),
        ]);
    }

    public function restoreFromTrash(string $type, int $id): RedirectResponse
    {
        $item = $type === 'folder' ? Folder::onlyTrashed()->findOrFail($id) : StoredFile::onlyTrashed()->findOrFail($id);
        $parent = $item instanceof Folder ? $item->parent : $item->folder;
        abort_if($parent === null || $parent->trashed(), 404);
        $this->authorizeFolder($parent, 'delete');
        abort_unless(! $item instanceof Folder || $this->ownRulesAllowDelete(CurrentUser::get(), $item), 404);

        $siblings = $item instanceof Folder ? Folder::where('parent_id', $parent->id) : StoredFile::where('folder_id', $parent->id);
        if ($siblings->where('name', $item->name)->exists()) {
            $info = pathinfo($item->name);
            $item->name = ($info['filename'] ?? $item->name).' (restored)'.(isset($info['extension']) && $item instanceof StoredFile ? '.'.$info['extension'] : '');
        }
        $item->restore();

        return back()->with('status', "“{$item->name}” restored.");
    }

    public function emptyTrash(Request $request): RedirectResponse
    {
        $me = CurrentUser::get();
        $mine = $request->input('area') === 'mine';
        abort_unless($mine ? $me->hasPermission('my_files.delete') : $me->hasPermission('files.empty_trash'), 403);

        // Only what this person's trash shows them; items in folders they can't see stay.
        ['folders' => $folders, 'files' => $files] = $this->trashFor($me, $mine);
        $removed = $this->store->purgeItems($folders->all(), $files->all());

        return back()->with('status', "Trash emptied ({$removed} ".str('file')->plural($removed).' deleted for good).');
    }

    /**
     * The top-most trashed folders and files in an area that this person may
     * delete there (the contents of a trashed folder come back with it).
     *
     * @return array{folders: Collection<int, Folder>, files: Collection<int, StoredFile>}
     */
    private function trashFor(User $me, bool $mine): array
    {
        $root = $mine ? Folder::personalRootFor($me) : Folder::libraryRoot();
        $ids = $this->store->subtreeIds($root, true);
        $canDeleteIn = fn (?Folder $folder): bool => $folder !== null && ! $folder->trashed() && $this->access->allows($me, $folder, 'delete');

        return [
            'folders' => Folder::onlyTrashed()->whereIn('parent_id', $ids)->get()->filter(fn (Folder $folder) => $canDeleteIn($folder->parent) && $this->ownRulesAllowDelete($me, $folder))->values(),
            'files' => StoredFile::onlyTrashed()->whereIn('folder_id', $ids)->with('folder')->get()->filter(fn (StoredFile $file) => $canDeleteIn($file->folder))->values(),
        ];
    }

    /**
     * A trashed top-level library folder carries its own access rules (its
     * parent, the library root, has none), so judge it by them.
     */
    private function ownRulesAllowDelete(User $me, Folder $folder): bool
    {
        return ! $folder->parent?->isRoot() || $folder->isPersonal() || $this->access->rulesAllow($me, $folder, 'delete');
    }

    private function browse(Request $request, Folder $folder, string $view = 'files.browse'): View
    {
        $this->authorizeFolder($folder, 'view');
        $me = CurrentUser::get();

        $children = $folder->children()->with('access')->get()
            ->filter(fn (Folder $child) => $folder->isPersonal() || ! $folder->isRoot() || $this->access->rulesAllow($me, $child, 'view'));

        return view($view, [
            'folder' => $folder,
            'ancestry' => $folder->ancestry(),
            'children' => $children->values(),
            'files' => $folder->files()->with(['currentVersion.uploader'])->withCount('versions')->get(),
            'can' => collect(['upload', 'edit', 'delete'])->mapWithKeys(fn ($ability) => [$ability => $this->access->allows($me, $folder, $ability)]),
            'canManageAccess' => ! $folder->isPersonal() && $folder->parent_id === Folder::libraryRoot()->id && $me->hasPermission('files.manage_access'),
            'moveTargets' => $view === 'files.browse' ? $this->moveTargets($folder) : collect(),
            'newIn' => $this->access->allows($me, $folder, 'upload') ? collect([$folder]) : collect(),
            'usage' => $folder->isPersonal() ? $this->store->usage($me) : null,
        ]);
    }

    /**
     * Folders in the same area a file here could be moved to.
     *
     * @return Collection<int, Folder>
     */
    private function moveTargets(Folder $folder): Collection
    {
        $me = CurrentUser::get();
        $ids = $folder->isPersonal() ? $this->visibleSubtree(Folder::personalRootFor($me)) : $this->access->visibleLibraryFolderIds($me);

        return Folder::whereIn('id', $ids)->whereKeyNot($folder->id)->get()
            ->filter(fn (Folder $target) => $this->access->allows($me, $target, 'upload'))
            ->sortBy(fn (Folder $target) => $target->path())
            ->values();
    }

    /**
     * @return list<int>
     */
    private function visibleSubtree(Folder $root): array
    {
        $ids = [$root->id];
        $frontier = [$root->id];
        while ($frontier !== []) {
            $frontier = Folder::whereIn('parent_id', $frontier)->pluck('id')->all();
            array_push($ids, ...$frontier);
        }

        return $ids;
    }

    /**
     * Not allowed to view (or in the trash): 404, so folder names don't leak.
     * Allowed to view but not this: 403.
     */
    private function authorizeFolder(Folder $folder, string $ability): void
    {
        $me = CurrentUser::get();
        abort_if(collect($folder->ancestry())->contains(fn (Folder $f) => $f->trashed()), 404);
        abort_unless($this->access->allows($me, $folder, 'view'), 404);
        abort_unless($this->access->allows($me, $folder, $ability), 403);
    }

    /**
     * Files in folders follow the folder's rules; attachments follow their page
     * (view it to download; delete if you added it or may edit the page).
     */
    private function authorizeFile(StoredFile $file, string $ability): void
    {
        abort_if($file->currentVersion === null, 404);

        if ($file->attachable_type !== null) {
            $me = CurrentUser::get();
            abort_unless(Attachables::canView($me, $file->attachable_type, (string) $file->attachable_id), 404);
            abort_unless($ability === 'view' || ($ability === 'delete' && Attachables::canDelete($me, $file)), 403);

            return;
        }

        abort_if($file->folder === null, 404);
        $this->authorizeFolder($file->folder, $ability);
    }

    /**
     * Where a file lives, for "back" links: its folder or the page it's attached to.
     *
     * @return array{url: string, label: string}
     */
    private function locationOf(StoredFile $file): array
    {
        if ($file->attachable_type !== null) {
            return [
                'url' => Attachables::url($file->attachable_type, (string) $file->attachable_id),
                'label' => 'Attached to a '.strtolower(Attachables::TYPES[$file->attachable_type] ?? 'page'),
            ];
        }

        return ['url' => $this->folderUrl($file->folder), 'label' => $file->folder->path()];
    }

    private function authorizeTopLevelAccess(Folder $folder): void
    {
        abort_unless(! $folder->isPersonal() && $folder->parent_id === Folder::libraryRoot()->id, 404);
        abort_unless(CurrentUser::get()->hasPermission('files.manage_access'), 403);
    }

    /**
     * @param  list<string>  $abilities
     * @return list<string>
     */
    private function withView(array $abilities): array
    {
        return array_values(array_intersect(array_keys(FolderAccess::ABILITIES), [...$abilities, 'view']));
    }

    private function folderUrl(?Folder $folder): string
    {
        return match (true) {
            $folder === null => route('files.library'),
            $folder->isRoot() && $folder->isPersonal() => route('files.mine'),
            $folder->isRoot() => route('files.library'),
            default => route('files.folder', $folder),
        };
    }
}
