<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Department;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FilesTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('files');
        $this->alex = User::factory()->withPermissions('files.delete', 'files.manage_access', 'files.empty_trash')->create(['name' => 'Alex Morgan']);
        $this->actingAs($this->alex);
    }

    private function upload(Folder $folder, string $name, string $content = 'hello'): StoredFile
    {
        $this->post(route('files.upload', $folder), ['files' => [UploadedFile::fake()->createWithContent($name, $content)]])->assertSessionHasNoErrors();

        return StoredFile::where('folder_id', $folder->id)->where('name', $name)->sole();
    }

    // Uploads, downloads and versions

    public function test_uploading_stores_the_content_in_s3_and_records_a_version(): void
    {
        $folder = Folder::factory()->create(['name' => 'Quality']);

        $file = $this->upload($folder, 'checklist.txt', 'Check the doors.');

        $version = $file->currentVersion;
        $this->assertSame(1, $version->number);
        $this->assertSame(hash('sha256', 'Check the doors.'), $version->sha256);
        Storage::disk('files')->assertExists($version->path);
        $this->assertStringNotContainsString('checklist', $version->path);

        $this->get(route('files.folder', $folder))->assertOk()->assertSee('checklist.txt');
        $download = $this->get(route('files.download', $file))->assertOk();
        $this->assertSame('Check the doors.', $download->streamedContent());
        $this->assertStringContainsString('attachment; filename=checklist.txt', $download->headers->get('Content-Disposition'));
    }

    public function test_the_same_name_becomes_a_new_version_and_old_versions_can_be_restored(): void
    {
        $folder = Folder::factory()->create();
        $file = $this->upload($folder, 'plan.txt', 'first');
        $this->upload($folder, 'plan.txt', 'second');

        $this->assertSame(1, StoredFile::count());
        $this->assertSame([2, 1], $file->versions()->pluck('number')->all());
        $this->assertSame('second', $this->get(route('files.download', $file))->streamedContent());

        $first = $file->versions()->where('number', 1)->sole();
        $this->get(route('files.history', $file))->assertSeeInOrder(['2', 'Current', '1']);
        $this->post(route('files.versions.restore', [$file, $first]))->assertSessionHas('status', 'Version 1 restored as version 3.');

        $this->assertSame('first', $this->get(route('files.download', $file->fresh()))->streamedContent());
        $this->assertSame('second', $this->get(route('files.versions.download', [$file, $file->versions()->where('number', 2)->sole()]))->streamedContent());
    }

    public function test_rename_move_and_folders(): void
    {
        $quality = Folder::factory()->create(['name' => 'Quality']);
        $this->post(route('files.folders.store', $quality), ['name' => 'Audits'])->assertRedirect();
        $audits = Folder::where('name', 'Audits')->sole();
        $file = $this->upload($quality, 'a.txt');

        $this->patch(route('files.rename', $file), ['name' => 'audit-2026.txt'])->assertSessionHasNoErrors();
        $this->patch(route('files.move', $file), ['folder_id' => $audits->id])->assertSessionHasNoErrors();

        $this->assertSame(['audit-2026.txt', $audits->id], [$file->fresh()->name, $file->fresh()->folder_id]);
        $this->assertSame('Library / Quality / Audits', $audits->path());

        $this->upload($audits, 'b.txt');
        $this->patch(route('files.rename', StoredFile::where('name', 'b.txt')->sole()), ['name' => 'audit-2026.txt'])->assertSessionHasErrors('name');
        $this->patch(route('files.rename', $file), ['name' => '../../etc/passwd'])->assertSessionHasNoErrors();
        $this->assertSame('passwd', $file->fresh()->name);
    }

    // Access

    public function test_folders_can_be_limited_to_a_department_and_subfolders_follow(): void
    {
        $qualityDept = Department::factory()->create(['name' => 'Quality']);
        $inside = User::factory()->for($qualityDept)->create();
        $outside = User::factory()->create();
        $folder = Folder::factory()->create(['name' => 'Quality']);
        $sub = Folder::factory()->create(['name' => 'Audits', 'parent_id' => $folder->id]);
        $file = $this->upload($sub, 'secret.txt');

        $this->put(route('files.access.update', $folder), ['departments' => [$qualityDept->id => ['upload']]])->assertRedirect();
        $this->assertSame(['view', 'upload'], $folder->access()->sole()->abilities);

        $this->actingAs($inside);
        $this->get(route('files.library'))->assertSee('Quality');
        $this->get(route('files.download', $file))->assertOk();
        $this->post(route('files.upload', $sub), ['files' => [UploadedFile::fake()->createWithContent('new.txt', 'x')]])->assertSessionHasNoErrors();
        $this->delete(route('files.destroy', $file))->assertForbidden();

        $this->actingAs($outside);
        $this->get(route('files.library'))->assertDontSee('Quality');
        $this->get(route('files.folder', $sub))->assertNotFound();
        $this->get(route('files.download', $file))->assertNotFound();
        $this->get(route('files.search', ['q' => 'secret']))->assertDontSee('secret.txt');
    }

    public function test_the_tag_grid_and_the_folder_rules_must_both_allow_it(): void
    {
        $folder = Folder::factory()->create();
        Tag::everyone()->update(['permissions' => ['files.view']]);
        $viewer = User::factory()->create();

        $this->actingAs($viewer);
        $this->get(route('files.folder', $folder))->assertOk()->assertDontSee('data-drop-zone', false);
        $this->post(route('files.upload', $folder), ['files' => [UploadedFile::fake()->createWithContent('x.txt', 'x')]])->assertForbidden();
        $this->get(route('files.access.edit', $folder))->assertForbidden();
    }

    public function test_personal_files_are_private(): void
    {
        $this->get(route('files.mine'))->assertOk()->assertSee('Only you can see these');
        $mine = Folder::personalRootFor($this->alex);
        $file = $this->upload($mine, 'payslip.pdf', '%PDF-1.4 test');

        $other = User::factory()->withPermissions('files.view', 'files.delete', 'files.manage_access')->create();
        $this->actingAs($other);
        $this->get(route('files.folder', $mine))->assertNotFound();
        $this->get(route('files.download', $file))->assertNotFound();
        $this->get(route('files.mine'))->assertDontSee('payslip.pdf');
        $this->get(route('files.search', ['q' => 'payslip']))->assertDontSee('payslip.pdf');
    }

    // Limits

    public function test_size_blocked_types_disguised_programs_and_quotas_are_refused(): void
    {
        $folder = Folder::factory()->create();

        $this->post(route('files.upload', $folder), ['files' => [UploadedFile::fake()->createWithContent('run.exe', 'x')]])->assertSessionHasErrors('files');
        $this->post(route('files.upload', $folder), ['files' => [UploadedFile::fake()->createWithContent('report.pdf', "MZ\x90\x00".str_repeat("\x00", 60)."PE\x00\x00")]])
            ->assertSessionHasErrors('files');

        AppSetting::put('files.max_mb', 1);
        $this->post(route('files.upload', $folder), ['files' => [UploadedFile::fake()->create('big.bin', 1100)]])->assertSessionHasErrors('files');

        AppSetting::put('files.personal_quota_mb', 1);
        $this->post(route('files.upload', Folder::personalRootFor($this->alex)), ['files' => [UploadedFile::fake()->create('a.bin', 600)]])->assertSessionHasNoErrors();
        $this->post(route('files.upload', Folder::personalRootFor($this->alex)), ['files' => [UploadedFile::fake()->create('b.bin', 600)]])->assertSessionHasErrors('files');

        $this->assertSame(1, StoredFile::count());
    }

    public function test_file_settings_save_the_limits(): void
    {
        $this->actingAs(User::factory()->withPermissions('file_settings.view', 'file_settings.edit')->create());

        $this->put(route('file-settings.update'), ['max_mb' => 50, 'blocked' => '.EXE, bat ,  sh', 'personal_quota_mb' => 500, 'total_quota_mb' => 0])->assertSessionHasNoErrors();

        $this->assertSame(['exe', 'bat', 'sh'], AppSetting::get('files.blocked_extensions'));
        $this->assertSame(50, AppSetting::get('files.max_mb'));

        $this->actingAs($this->alex)->put(route('file-settings.update'), ['max_mb' => 1, 'personal_quota_mb' => 0, 'total_quota_mb' => 0])->assertForbidden();
    }

    // Preview

    public function test_only_safe_types_are_shown_in_the_browser(): void
    {
        $folder = Folder::factory()->create();
        $png = $this->upload($folder, 'photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $svg = $this->upload($folder, 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $html = $this->upload($folder, 'page.html', '<html><script>alert(1)</script></html>');
        $text = $this->upload($folder, 'notes.txt', 'Line one <b>bold</b>');

        $image = $this->get(route('files.raw', $png))->assertOk();
        $this->assertSame('image/png', $image->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', $image->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $image->headers->get('X-Content-Type-Options'));

        foreach ([$svg, $html] as $unsafe) {
            $response = $this->get(route('files.raw', $unsafe))->assertOk();
            $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
        }

        $this->get(route('files.preview', $png))->assertSee('<img src="'.route('files.raw', $png).'"', false);
        $this->get(route('files.preview', $text))->assertSee('Line one &lt;b&gt;bold&lt;/b&gt;', false);
        $this->get(route('files.preview', $svg))->assertSee('can’t be shown here');
    }

    // Search and trash

    public function test_search_finds_files_and_folders_by_name(): void
    {
        $folder = Folder::factory()->create(['name' => 'Safety']);
        $this->upload($folder, 'Fire drill plan.txt');

        $this->get(route('files.search', ['q' => 'DRILL']))->assertSee('Fire drill plan.txt')->assertSee('Library / Safety');
        $this->get(route('files.search', ['q' => 'safe']))->assertSee('Safety');
    }

    public function test_trash_restore_and_purge(): void
    {
        $folder = Folder::factory()->create(['name' => 'Old']);
        $file = $this->upload($folder, 'keep.txt');
        $gone = $this->upload($folder, 'gone.txt');
        $paths = $gone->versions()->pluck('path')->all();

        $this->delete(route('files.destroy', $file));
        $this->delete(route('files.destroy', $gone));
        $this->get(route('files.folder', $folder))->assertDontSee('keep.txt');
        $this->get(route('files.trash'))->assertSee('keep.txt')->assertSee('gone.txt');

        $this->post(route('files.trash.restore', ['file', $file->id]))->assertSessionHas('status', '“keep.txt” restored.');
        $this->get(route('files.folder', $folder))->assertSee('keep.txt');

        $this->travel(31)->days();
        $this->artisan('files:empty-trash')->expectsOutput('Deleted 1 file for good.')->assertSuccessful();
        $this->assertModelMissing($gone);
        Storage::disk('files')->assertMissing($paths[0]);
        Storage::disk('files')->assertExists($file->currentVersion->path);
    }

    public function test_a_deleted_folder_hides_and_restores_its_contents(): void
    {
        $folder = Folder::factory()->create(['name' => 'Projects']);
        $file = $this->upload($folder, 'p.txt');

        $this->delete(route('files.folders.destroy', $folder))->assertRedirect(route('files.library'));
        $this->get(route('files.download', $file))->assertNotFound();
        $this->get(route('files.trash'))->assertSee('Projects')->assertDontSee('p.txt');

        $this->post(route('files.trash.restore', ['folder', $folder->id]));
        $this->get(route('files.download', $file))->assertOk();

        $this->delete(route('files.folders.destroy', $folder));
        $this->post(route('files.trash.empty'), ['area' => 'library'])->assertSessionHas('status');
        $this->assertNull(Folder::withTrashed()->find($folder->id));
        $this->assertSame(0, FileVersion::count());
    }
}
