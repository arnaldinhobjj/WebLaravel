<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Services\Files\FileStore;
use App\Support\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Uploading a material's blueprint, photo or 3D model. The file is attached
 * to the material (see App\Support\Attachables) and replaces the slot's
 * previous file, which goes to the trash.
 */
class MaterialFileController extends Controller
{
    /** @var array<string, list<string>> slot => accepted extensions */
    private const ACCEPTED = [
        'blueprint' => ['png', 'jpg', 'jpeg', 'webp', 'pdf'],
        'image' => ['png', 'jpg', 'jpeg', 'webp'],
        'model' => ['glb', 'gltf', 'stl'],
    ];

    /** @var array<string, string> */
    private const LABELS = ['blueprint' => 'Blueprint', 'image' => 'Image', 'model' => '3D model'];

    public function store(Request $request, Material $material, string $slot, FileStore $store): RedirectResponse
    {
        $accepted = self::ACCEPTED[$slot];
        // 3D models have no reliable content type, so those go by extension only.
        $rules = $slot === 'model'
            ? ['required', 'file', 'extensions:'.implode(',', $accepted)]
            : ['required', 'file', 'extensions:'.implode(',', $accepted), 'mimes:'.implode(',', $accepted)];

        $request->validate(['file' => $rules], [
            'file.extensions' => self::LABELS[$slot].' files must be '.implode(', ', $accepted).'.',
            'file.mimes' => self::LABELS[$slot].' files must be '.implode(', ', $accepted).'.',
            'file.uploaded' => 'The file couldn’t be uploaded; it may be larger than the server allows.',
        ]);

        $upload = $request->file('file');
        $store->assertAcceptable($upload);
        $file = $store->attach(CurrentUser::get(), 'material', (string) $material->id, $upload);

        $column = Material::FILE_SLOTS[$slot];
        $previous = $material->{$slot.'File'};
        $material->forceFill([$column => $file->id])->save();
        $previous?->delete();

        return redirect()->route('materials.show', ['material' => $material, 'view' => $slot])
            ->with('status', self::LABELS[$slot].' uploaded.');
    }
}
