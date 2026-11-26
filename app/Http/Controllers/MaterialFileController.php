<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Services\Files\FileStore;
use App\Support\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

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
        if (strtolower($upload->getClientOriginalExtension()) === 'gltf') {
            $this->assertSelfContainedGltf($upload);
        }
        $store->assertAcceptable($upload);
        $file = $store->attach(CurrentUser::get(), 'material', (string) $material->id, $upload);

        $column = Material::FILE_SLOTS[$slot];
        $previous = $material->{$slot.'File'};
        $material->forceFill([$column => $file->id])->save();
        $previous?->delete();

        return redirect()->route('materials.show', ['material' => $material, 'view' => $slot])
            ->with('status', self::LABELS[$slot].' uploaded.');
    }

    /**
     * The viewer gets one file, so a .gltf must embed its buffers and images
     * (data: URIs) rather than point to files next to it. A .glb always does.
     */
    private function assertSelfContainedGltf(UploadedFile $upload): void
    {
        $gltf = json_decode((string) file_get_contents($upload->getRealPath()), true);

        if (! is_array($gltf) || ! isset($gltf['asset'])) {
            throw ValidationException::withMessages(['file' => 'This .gltf file isn’t a valid glTF model.']);
        }

        $external = collect([...(array) ($gltf['buffers'] ?? []), ...(array) ($gltf['images'] ?? [])])
            ->map(fn (mixed $resource): mixed => is_array($resource) ? ($resource['uri'] ?? null) : null)
            ->filter(fn (mixed $uri): bool => is_string($uri) && ! str_starts_with($uri, 'data:'));

        if ($external->isNotEmpty()) {
            throw ValidationException::withMessages(['file' => 'This .gltf model uses separate files ('.$external->take(3)->join(', ').'). Upload it as a single .glb, or export the .gltf with embedded data.']);
        }
    }
}
