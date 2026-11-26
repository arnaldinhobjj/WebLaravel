<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Material;
use App\Models\MaterialCharpyTest;
use App\Models\MaterialElement;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\User;
use App\Services\MaterialKpis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaterialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('files');
        $this->withoutVite();
        // Start from nothing: each test grants exactly what it needs.
        Tag::everyone()?->update(['permissions' => []]);
    }

    public function test_lists_materials_with_whether_they_meet_their_specification(): void
    {
        $this->actingAs(User::factory()->withPermissions('materials.view')->create());
        Material::factory()->withTests()->create(['code' => 'MAT-0001']);
        $failing = Material::factory()->withTests()->create(['code' => 'MAT-0002']);
        $failing->charpyTests()->first()->update(['energy_j' => 5]);

        $this->get(route('materials.index'))
            ->assertOk()
            ->assertSeeInOrder(['MAT-0001', 'Meets spec', 'MAT-0002', 'Out of spec']);
    }

    public function test_shows_the_composition_tests_and_kpis_of_a_material(): void
    {
        $this->actingAs(User::factory()->withPermissions('materials.view')->create());
        $material = Material::factory()->withTests()->create(['code' => 'MAT-0001', 'grade' => 'ASTM A516 Gr.70']);
        $material->tensileTests()->first()->update(['specimen' => 'T-SPECIMEN']);
        $material->charpyTests()->first()->update(['specimen' => 'C-SPECIMEN']);

        $this->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('ASTM A516 Gr.70')
            ->assertSeeInOrder(['Blueprint', '2D', '3D', 'Image'])
            ->assertSee(['Checks passed', 'Yield strength (avg)', 'Absorbed energy (avg)', 'Carbon equivalent CE (IIW)'])
            ->assertSee(['Composition and chemical analysis', '0.790–1.300'])
            ->assertSee(['T-SPECIMEN', 'C-SPECIMEN'])
            ->assertSee('No blueprint yet.')
            ->assertDontSee('to add the blueprint');
    }

    public function test_materials_need_the_view_permission_and_stay_out_of_the_menu_without_it(): void
    {
        $this->actingAs(User::factory()->create());
        $material = Material::factory()->create();

        $this->get(route('materials.index'))->assertForbidden();
        $this->get(route('materials.show', $material))->assertForbidden();
        $this->get(route('dashboard'))->assertDontSee(route('materials.index'));

        $this->actingAs(User::factory()->withPermissions('materials.view')->create());
        $this->get(route('dashboard'))->assertSee(route('materials.index'));
    }

    public function test_uploading_a_file_fills_its_slot_and_replaces_the_previous_one(): void
    {
        $this->actingAs(User::factory()->withPermissions('materials.view', 'materials.edit')->create());
        $material = Material::factory()->create();

        $this->post(route('materials.files.store', [$material, 'image']), ['file' => UploadedFile::fake()->image('first.png')])
            ->assertRedirect(route('materials.show', ['material' => $material, 'view' => 'image']));
        $first = $material->refresh()->imageFile;
        $this->assertSame('first.png', $first->name);

        $this->post(route('materials.files.store', [$material, 'image']), ['file' => UploadedFile::fake()->image('second.png')]);

        $this->assertSame('second.png', $material->refresh()->imageFile->name);
        $this->assertSoftDeleted($first);
        $this->get(route('materials.show', $material))->assertSee(route('files.raw', $material->image_file_id));
        $this->get(route('files.raw', $material->image_file_id))->assertOk();
    }

    public function test_a_3d_model_is_accepted_by_its_extension(): void
    {
        $this->actingAs(User::factory()->withPermissions('materials.view', 'materials.edit')->create());
        $material = Material::factory()->create();

        $this->post(route('materials.files.store', [$material, 'model']), ['file' => UploadedFile::fake()->createWithContent('part.glb', "glTF\x02\x00\x00\x00")])
            ->assertSessionHasNoErrors();

        $this->assertSame('part.glb', $material->refresh()->modelFile->name);
        $this->get(route('materials.show', $material))->assertSee('data-model-format="glb"', false);
    }

    public function test_a_gltf_model_must_embed_its_buffers_and_images(): void
    {
        $this->actingAs(User::factory()->withPermissions('materials.view', 'materials.edit')->create());
        $material = Material::factory()->create();
        $gltf = fn (array $buffers): string => json_encode(['asset' => ['version' => '2.0'], 'buffers' => $buffers]);

        $this->post(route('materials.files.store', [$material, 'model']), ['file' => UploadedFile::fake()->createWithContent('part.gltf', $gltf([['uri' => 'part.bin', 'byteLength' => 12]]))])
            ->assertSessionHasErrors(['file' => 'This .gltf model uses separate files (part.bin). Upload it as a single .glb, or export the .gltf with embedded data.']);
        $this->post(route('materials.files.store', [$material, 'model']), ['file' => UploadedFile::fake()->createWithContent('part.gltf', 'not json')])
            ->assertSessionHasErrors('file');
        $this->assertNull($material->refresh()->model_file_id);
        $this->assertSame(0, StoredFile::count());

        $this->post(route('materials.files.store', [$material, 'model']), ['file' => UploadedFile::fake()->createWithContent('part.gltf', $gltf([['uri' => 'data:application/octet-stream;base64,AAAA', 'byteLength' => 3]]))])
            ->assertSessionHasNoErrors();
        $this->assertSame('part.gltf', $material->refresh()->modelFile->name);
    }

    public function test_cad_models_and_dxf_drawings_go_in_their_own_views(): void
    {
        $this->actingAs(User::factory()->withPermissions('materials.view', 'materials.edit')->create());
        $material = Material::factory()->create();

        $this->post(route('materials.files.store', [$material, 'model']), ['file' => UploadedFile::fake()->createWithContent('flange.STEP', "ISO-10303-21;\nHEADER;\nENDSEC;\n")])
            ->assertSessionHasNoErrors();
        $this->post(route('materials.files.store', [$material, 'drawing']), ['file' => UploadedFile::fake()->createWithContent('flange.dxf', "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n")])
            ->assertRedirect(route('materials.show', ['material' => $material, 'view' => 'drawing']));

        $material->refresh();
        $this->assertSame('flange.STEP', $material->modelFile->name);
        $this->assertSame('flange.dxf', $material->drawingFile->name);
        $this->get(route('materials.show', $material))
            ->assertSee('data-model-format="step" data-model-kind="3d"', false)
            ->assertSee('data-model-format="dxf" data-model-kind="2d"', false);
    }

    public function test_native_cad_files_are_refused_with_what_to_export_instead(): void
    {
        $this->actingAs(User::factory()->withPermissions('materials.view', 'materials.edit')->create());
        $material = Material::factory()->create();

        $this->post(route('materials.files.store', [$material, 'model']), ['file' => UploadedFile::fake()->createWithContent('bracket.SLDPRT', 'binary')])
            ->assertSessionHasErrors(['file' => '“bracket.SLDPRT” is a native CAD file, which browsers can’t display. Export it from your CAD program as a STEP (.step) or IGES file for the 3D view.']);
        $this->post(route('materials.files.store', [$material, 'drawing']), ['file' => UploadedFile::fake()->createWithContent('layout.dwg', 'binary')])
            ->assertSessionHasErrors(['file' => '“layout.dwg” is a native CAD file, which browsers can’t display. Export it from your CAD program as a DXF for the 2D view.']);

        $this->assertNull($material->refresh()->model_file_id);
        $this->assertNull($material->drawing_file_id);
        $this->assertSame(0, StoredFile::count());
    }

    public function test_uploads_must_match_their_slot(): void
    {
        $this->actingAs(User::factory()->withPermissions('materials.view', 'materials.edit')->create());
        $material = Material::factory()->create();

        $this->post(route('materials.files.store', [$material, 'image']), ['file' => UploadedFile::fake()->createWithContent('drawing.pdf', '%PDF-1.4')])
            ->assertSessionHasErrors('file');
        $this->post(route('materials.files.store', [$material, 'model']), ['file' => UploadedFile::fake()->image('part.png')])
            ->assertSessionHasErrors('file');

        $this->assertNull($material->refresh()->image_file_id);
        $this->assertNull($material->model_file_id);
        $this->assertSame(0, StoredFile::count());
    }

    public function test_an_upload_over_the_size_limit_is_explained_on_the_page(): void
    {
        $this->actingAs(User::factory()->withPermissions('materials.view', 'materials.edit')->create());
        $material = Material::factory()->create();
        AppSetting::put('files.max_mb', 1);

        $this->from(route('materials.show', $material))
            ->followingRedirects()
            ->post(route('materials.files.store', [$material, 'image']), ['file' => UploadedFile::fake()->image('big.png')->size(2048)])
            ->assertSee('“big.png” is larger than the 1 MB limit.');

        $this->assertNull($material->refresh()->image_file_id);
    }

    public function test_uploading_needs_the_edit_permission(): void
    {
        $this->actingAs(User::factory()->withPermissions('materials.view')->create());
        $material = Material::factory()->create();

        $this->post(route('materials.files.store', [$material, 'image']), ['file' => UploadedFile::fake()->image('photo.png')])
            ->assertForbidden();

        $this->assertNull($material->refresh()->image_file_id);
    }

    public function test_charpy_tests_at_another_temperature_are_not_judged_against_the_spec(): void
    {
        $this->actingAs(User::factory()->withPermissions('materials.view')->create());
        $material = Material::factory()->withTests()->create(['charpy_temperature_c' => -20, 'spec_min_charpy_single_j' => 20]);
        $material->charpyTests()->update(['temperature_c' => -20]);
        $colder = MaterialCharpyTest::factory()->for($material)->create(['specimen' => 'C-COLD', 'temperature_c' => -80, 'energy_j' => 8]);

        $this->assertNull($colder->meetsSpec());
        $this->assertTrue(app(MaterialKpis::class)->verdict($material->load(['elements', 'tensileTests', 'charpyTests'])));
        $this->get(route('materials.show', $material))->assertSee('C-COLD')->assertDontSee('Out of spec');
    }

    public function test_kpis_compute_carbon_equivalents_from_the_measured_chemistry(): void
    {
        $material = Material::factory()->create();
        foreach (['C' => 0.18, 'Mn' => 1.20, 'Si' => 0.30, 'Cr' => 0.10, 'Mo' => 0.05, 'V' => 0.02, 'Ni' => 0.15, 'Cu' => 0.15, 'B' => 0.001] as $element => $measured) {
            MaterialElement::factory()->for($material)->create(['element' => $element, 'spec_min_pct' => null, 'spec_max_pct' => null, 'measured_pct' => $measured]);
        }

        $kpis = app(MaterialKpis::class)->for($material->load(['elements', 'tensileTests', 'charpyTests']));

        // 0.18 + 1.20/6 + (0.10+0.05+0.02)/5 + (0.15+0.15)/15
        $this->assertEqualsWithDelta(0.434, $kpis['weldability']['ce_iiw'], 0.0005);
        // 0.18 + 0.30/30 + (1.20+0.15+0.10)/20 + 0.15/60 + 0.05/15 + 0.02/10 + 5*0.001
        $this->assertEqualsWithDelta(0.275, $kpis['weldability']['pcm'], 0.0005);
        $this->assertNull($kpis['verdict']);
    }

    public function test_one_charpy_result_below_the_single_minimum_fails_the_material(): void
    {
        $material = Material::factory()->withTests()->create(['spec_min_charpy_avg_j' => 27, 'spec_min_charpy_single_j' => 20]);
        $kpis = app(MaterialKpis::class);

        $this->assertTrue($kpis->verdict($material->load(['elements', 'tensileTests', 'charpyTests'])));

        MaterialCharpyTest::factory()->for($material)->create(['energy_j' => 15, 'temperature_c' => $material->charpy_temperature_c]);
        $result = $kpis->for($material->load(['elements', 'tensileTests', 'charpyTests']));

        $this->assertFalse($result['verdict']);
        $this->assertSame(15.0, $result['charpy']['minimum_j']);
        $this->assertContains(['label' => 'Charpy single values', 'passed' => false], $result['checks']);
        $this->assertContains(['label' => 'Charpy average', 'passed' => true], $result['checks']);
    }
}
