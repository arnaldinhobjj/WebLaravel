<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Services\MaterialKpis;
use Illuminate\View\View;

/**
 * The Materials page: each material's composition, chemical analysis,
 * tensile and Charpy tests, a viewer for the part and its KPIs.
 */
class MaterialController extends Controller
{
    public function index(MaterialKpis $kpis): View
    {
        $materials = Material::with(['elements', 'tensileTests', 'charpyTests'])->orderBy('code')->get();

        return view('materials.index', [
            'materials' => $materials,
            'verdicts' => $materials->mapWithKeys(fn (Material $material): array => [$material->id => $kpis->verdict($material)]),
        ]);
    }

    public function show(Material $material, MaterialKpis $kpis): View
    {
        $material->load([
            'elements', 'tensileTests', 'charpyTests',
            'blueprintFile.currentVersion', 'imageFile.currentVersion', 'modelFile.currentVersion',
        ]);

        return view('materials.show', [
            'material' => $material,
            'kpis' => $kpis->for($material),
        ]);
    }
}
