<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Folder;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('files');
        $this->alex = User::factory()->withPermissions('files.delete')->create(['name' => 'Alex Morgan']);
        $this->actingAs($this->alex);
    }

    private function upload(Folder $folder, string $name, string $content = 'x'): void
    {
        $this->post(route('files.upload', $folder), ['files' => [UploadedFile::fake()->createWithContent($name, $content)]])->assertSessionHasNoErrors();
    }

    public function test_the_top_shows_the_areas_you_may_open_as_folders(): void
    {
        $this->get('/')->assertSee('href="'.route('workspace.index').'"', false);

        $this->get(route('workspace.index'))->assertOk()
            ->assertSee('class="finder-folder finder-folder-shared"', false)
            ->assertSee('href="'.route('workspace.folder', Folder::libraryRoot()).'"', false)
            ->assertSee('href="'.route('workspace.folder', Folder::personalRootFor($this->alex)).'"', false)
            ->assertSeeInOrder(['Library', 'My files']);

        Tag::everyone()->update(['permissions' => []]);
        $nobody = User::factory()->create();
        $this->actingAs($nobody)->get(route('workspace.index'))->assertForbidden();
        $this->get('/')->assertDontSee('href="'.route('workspace.index').'"', false);
    }

    public function test_clicking_a_folder_shows_its_folders_and_files_as_icons_at_any_depth(): void
    {
        $library = Folder::libraryRoot();

        // Folders inside folders, made from the workspace, three levels deep.
        $this->post(route('files.folders.store', $library), ['name' => 'Projects', 'from' => 'workspace']);
        $projects = Folder::where('name', 'Projects')->sole();
        $this->post(route('files.folders.store', $projects), ['name' => '2026', 'from' => 'workspace'])
            ->assertRedirect(route('workspace.folder', Folder::where('name', '2026')->sole()));
        $year = Folder::where('name', '2026')->sole();
        $this->post(route('files.folders.store', $year), ['name' => 'Bridge', 'from' => 'workspace']);
        $bridge = Folder::where('name', 'Bridge')->sole();

        $this->upload($projects, 'plan.pdf', '%PDF-1.4');
        $this->upload($bridge, 'survey.xlsx');

        $this->get(route('workspace.folder', $library))->assertSee('Projects')->assertSee('href="'.route('workspace.folder', $projects).'"', false);
        $this->get(route('workspace.folder', $projects))
            ->assertSee('href="'.route('workspace.folder', $year).'"', false)
            ->assertSeeInOrder(['finder-folder', '2026', 'finder-doc-pdf', 'PDF', 'plan.pdf'], false)
            ->assertSee('1 folder, 1 file');
        $this->get(route('workspace.folder', $bridge))
            ->assertSeeInOrder(['Files', 'Library', 'Projects', '2026', 'Bridge'])
            ->assertSee('href="'.route('workspace.folder', $year).'" class="tool-btn"', false)
            ->assertSee('survey.xlsx')
            ->assertSee('finder-doc-sheet', false)
            ->assertSee('href="'.route('files.preview', StoredFile::where('name', 'survey.xlsx')->sole()).'"', false);

        $this->assertSame('Library / Projects / 2026 / Bridge', $bridge->path());
    }

    public function test_images_show_a_thumbnail(): void
    {
        $folder = Folder::factory()->create();
        $this->upload($folder, 'photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        $photo = StoredFile::where('name', 'photo.png')->sole();
        $this->get(route('workspace.folder', $folder))->assertSee('<img src="'.route('files.raw', $photo).'"', false);
    }

    public function test_it_follows_folder_access_and_keeps_personal_files_private(): void
    {
        $hr = Folder::factory()->create(['name' => 'HR']);
        $hr->access()->create(['department_id' => Department::factory()->create()->id, 'abilities' => ['view']]);
        $inside = Folder::factory()->create(['name' => 'Payroll', 'parent_id' => $hr->id]);
        Folder::factory()->create(['name' => 'Open']);
        $mine = Folder::personalRootFor($this->alex);
        $this->upload($mine, 'diary.txt');

        $outsider = User::factory()->create();
        $this->actingAs($outsider);
        $this->get(route('workspace.folder', Folder::libraryRoot()))
            ->assertSee('<span class="finder-name">Open</span>', false)
            ->assertDontSee('<span class="finder-name">HR</span>', false)
            ->assertDontSee(route('workspace.folder', $hr));
        $this->get(route('workspace.folder', $hr))->assertNotFound();
        $this->get(route('workspace.folder', $inside))->assertNotFound();
        $this->get(route('workspace.folder', $mine))->assertNotFound();
        $this->get(route('workspace.folder', Folder::personalRootFor($outsider)))->assertOk()->assertDontSee('diary.txt');
    }

    public function test_uploading_and_deleting_from_the_workspace_stay_in_the_workspace(): void
    {
        $folder = Folder::factory()->create(['name' => 'Inbox']);
        $sub = Folder::factory()->create(['name' => 'Old', 'parent_id' => $folder->id]);

        $this->get(route('workspace.folder', $folder))->assertSee('data-drop-zone', false)->assertSee('New folder')->assertSee('Upload files…');
        $this->from(route('workspace.folder', $folder))
            ->post(route('files.upload', $folder), ['files' => [UploadedFile::fake()->createWithContent('note.txt', 'hi')]])
            ->assertRedirect(route('workspace.folder', $folder));

        $this->delete(route('files.folders.destroy', $sub), ['from' => 'workspace'])->assertRedirect(route('workspace.folder', $folder));

        Tag::everyone()->update(['permissions' => ['files.view']]);
        $this->actingAs(User::factory()->create())->get(route('workspace.folder', $folder))
            ->assertSee('note.txt')->assertDontSee('data-drop-zone', false)->assertDontSee('New folder');
    }

    public function test_the_new_menu_works_at_the_top_and_in_folders(): void
    {
        $library = Folder::libraryRoot();
        $mine = Folder::personalRootFor($this->alex);

        $this->get(route('workspace.index'))
            ->assertSee('New folder in Library')
            ->assertSee('New folder in My files')
            ->assertSee('action="'.route('files.folders.store', $library).'"', false)
            ->assertSee('action="'.route('files.folders.store', $mine).'"', false);

        $this->post(route('files.folders.store', $mine), ['name' => 'Receipts', 'from' => 'workspace'])
            ->assertRedirect(route('workspace.folder', Folder::where('name', 'Receipts')->sole()));
        $this->get(route('workspace.folder', $mine))->assertSee('<span class="finder-name">Receipts</span>', false);

        // People who can't add anything don't get the menu.
        Tag::everyone()->update(['permissions' => ['files.view']]);
        $this->actingAs(User::factory()->create())->get(route('workspace.folder', $library))->assertDontSee('finder-new', false);
    }

    public function test_the_trash_keeps_the_files_look(): void
    {
        $folder = Folder::factory()->create(['name' => 'Old stuff']);
        $this->upload($folder, 'draft.pdf', '%PDF-1.4');
        $draft = StoredFile::where('name', 'draft.pdf')->sole();
        $this->delete(route('files.destroy', $draft));

        $this->get(route('workspace.index'))->assertSee('href="'.route('workspace.trash').'"', false);
        $this->get(route('workspace.trash'))->assertOk()
            ->assertSee('class="finder-window card"', false)
            ->assertSee('finder-doc-pdf', false)
            ->assertSee('draft.pdf')
            ->assertSee('Restore draft.pdf');

        $this->from(route('workspace.trash'))->post(route('files.trash.restore', ['file', $draft->id]))->assertRedirect(route('workspace.trash'));
        $this->get(route('workspace.trash'))->assertSee('The trash is empty.');
        $this->get(route('workspace.trash', ['area' => 'mine']))->assertOk()->assertSee('My trash');
    }
}
