@extends('layouts.app')

@section('title', $material->code)

@php
    /** "512.3" for a number, "—" when there is none. */
    $number = fn (?float $value, int $decimals = 0): string => $value === null ? '—' : number_format($value, $decimals);

    /** "0.79–1.30", "≤ 0.27" or "≥ 0.13" for a specified range. */
    $range = fn (?float $min, ?float $max, int $decimals = 0): string => match (true) {
        $min !== null && $max !== null => number_format($min, $decimals).'–'.number_format($max, $decimals),
        $max !== null => '≤ '.number_format($max, $decimals),
        $min !== null => '≥ '.number_format($min, $decimals),
        default => '—',
    };

    /** Pass/fail of a value against a minimum (and maximum), or null when either is missing. */
    $meets = fn (?float $value, ?float $min, ?float $max = null): ?bool => $value === null || ($min === null && $max === null)
        ? null
        : ($min === null || $value >= $min) && ($max === null || $value <= $max);

    $slots = [
        'blueprint' => ['label' => 'Blueprint', 'icon' => 'blueprint', 'file' => $material->blueprintFile, 'accept' => '.png,.jpg,.jpeg,.webp,.pdf', 'hint' => 'PNG, JPG, WebP or PDF'],
        'model' => ['label' => '3D', 'icon' => 'cube', 'file' => $material->modelFile, 'accept' => '.glb,.gltf,.stl', 'hint' => 'GLB, STL, or glTF with embedded data'],
        'image' => ['label' => 'Image', 'icon' => 'image', 'file' => $material->imageFile, 'accept' => '.png,.jpg,.jpeg,.webp', 'hint' => 'PNG, JPG or WebP'],
    ];
    $activeSlot = array_key_exists((string) request('view'), $slots) ? request('view') : 'blueprint';
    $canUpload = auth()->user()->can('materials.edit');
    $temperature = $kpis['charpy']['temperature_c'];
@endphp

@section('content')
    <a href="{{ route('materials.index') }}" class="back-link"><x-icon name="arrow-left" :size="16" /> All materials</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">{{ $material->code }} · {{ $material->name }}</h2>
            <p class="page-sub">
                {{ collect([$material->part_name, $material->grade, $material->heat_number ? 'Heat '.$material->heat_number : null])->filter()->join(' · ') }}
            </p>
        </div>
        @include('materials._verdict', ['verdict' => $kpis['verdict']])
    </div>

    @if ($material->description)
        <p class="muted material-description">{{ $material->description }}</p>
    @endif

    {{-- Viewer: blueprint, 3D model and photo of the part --}}
    <section class="card viewer" aria-label="Part viewer" data-material-viewer data-material-id="{{ $material->id }}" @if (request()->has('view')) data-view-fixed @endif>
        <div class="viewer-head">
            <h3 class="sub-title">Part</h3>
            <div class="viewer-switch" role="tablist" aria-label="Choose a view">
                @foreach ($slots as $slot => $info)
                    <button type="button" role="tab" id="viewer-tab-{{ $slot }}" aria-controls="viewer-panel-{{ $slot }}"
                            aria-selected="{{ $slot === $activeSlot ? 'true' : 'false' }}" tabindex="{{ $slot === $activeSlot ? '0' : '-1' }}"
                            data-viewer-tab="{{ $slot }}">
                        <x-icon :name="$info['icon']" :size="15" /> {{ $info['label'] }}
                    </button>
                @endforeach
            </div>
        </div>

        @foreach ($slots as $slot => $info)
            @php
                $file = $info['file'];
                $mime = $file?->currentVersion?->mime;
                $url = $file ? route('files.raw', $file) : null;
            @endphp
            <div class="viewer-panel" role="tabpanel" id="viewer-panel-{{ $slot }}" aria-labelledby="viewer-tab-{{ $slot }}" data-viewer-panel="{{ $slot }}" @if ($slot !== $activeSlot) hidden @endif>
                <div class="viewer-stage">
                    @if ($file === null)
                        <div class="viewer-empty">
                            <x-icon :name="$info['icon']" :size="32" />
                            <p>No {{ $slot === 'model' ? '3D model' : strtolower($info['label']) }} yet.</p>
                        </div>
                    @elseif ($slot === 'model')
                        <div class="viewer-3d" data-model-url="{{ $url }}" data-model-format="{{ strtolower(pathinfo($file->name, PATHINFO_EXTENSION)) }}">
                            <p class="viewer-3d-status muted" data-model-status>Loading 3D model…</p>
                        </div>
                    @elseif ($mime === 'application/pdf')
                        <object data="{{ $url }}" type="application/pdf" class="viewer-pdf" aria-label="{{ $file->name }}">
                            <a href="{{ route('files.download', $file) }}">Download {{ $file->name }}</a>
                        </object>
                    @else
                        <img src="{{ $url }}" alt="{{ $info['label'] }} of {{ $material->name }}" class="viewer-image" loading="lazy">
                    @endif
                </div>

                <div class="viewer-foot">
                    @if ($file)
                        <span class="muted small">{{ $file->name }}</span>
                        <a href="{{ route('files.download', $file) }}" class="link-btn">Download</a>
                    @endif
                    @if ($canUpload)
                        <form method="POST" action="{{ route('materials.files.store', [$material, $slot]) }}" enctype="multipart/form-data" class="drop-zone drop-zone-small" data-drop-zone>
                            @csrf
                            <x-icon name="plus" :size="16" />
                            <label>
                                <span>Drop a file or <u>choose one</u> to {{ $file ? 'replace' : 'add' }} the {{ $slot === 'model' ? '3D model' : strtolower($info['label']) }}</span>
                                <input type="file" name="file" accept="{{ $info['accept'] }}" class="sr-only" data-drop-input>
                            </label>
                            <small class="muted">{{ $info['hint'] }}</small>
                            <noscript><button type="submit" class="btn">Upload</button></noscript>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
        @error('file') <p class="field-error">{{ $message }}</p> @enderror
    </section>

    {{-- KPIs --}}
    <section class="kpi-groups" aria-label="Key figures">
        <div class="kpi-group">
            <h3 class="sub-title">Specification</h3>
            <div class="kpi-grid">
                <div @class(['kpi', 'kpi-wide', 'pass' => $kpis['verdict'] === true, 'fail' => $kpis['verdict'] === false])>
                    <span class="kpi-label">Checks passed</span>
                    <span class="kpi-value">{{ $kpis['passed'] }}<small> / {{ $kpis['total'] }}</small></span>
                    <ul class="kpi-checks">
                        @forelse ($kpis['checks'] as $check)
                            <li @class(['pass' => $check['passed'], 'fail' => ! $check['passed']])>
                                <x-icon :name="$check['passed'] ? 'check' : 'x'" :size="13" /> {{ $check['label'] }}
                            </li>
                        @empty
                            <li class="muted">Nothing to check yet</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>

        <div class="kpi-group">
            <h3 class="sub-title">Strength</h3>
            <div class="kpi-grid">
                @include('materials._kpi', ['label' => 'Yield strength (avg)', 'value' => $number($kpis['strength']['yield_mpa']), 'unit' => 'MPa', 'spec' => $material->spec_min_yield_mpa !== null ? 'min '.$number($material->spec_min_yield_mpa) : null, 'passed' => $meets($kpis['strength']['yield_mpa'], $material->spec_min_yield_mpa)])
                @include('materials._kpi', ['label' => 'Tensile strength (avg)', 'value' => $number($kpis['strength']['uts_mpa']), 'unit' => 'MPa', 'spec' => $material->spec_min_uts_mpa !== null || $material->spec_max_uts_mpa !== null ? $range($material->spec_min_uts_mpa, $material->spec_max_uts_mpa) : null, 'passed' => $meets($kpis['strength']['uts_mpa'], $material->spec_min_uts_mpa, $material->spec_max_uts_mpa)])
                @include('materials._kpi', ['label' => 'Yield / tensile ratio', 'value' => $number($kpis['strength']['yield_tensile_ratio'], 2), 'unit' => null, 'spec' => null, 'passed' => null])
                @include('materials._kpi', ['label' => 'Elongation (avg)', 'value' => $number($kpis['strength']['elongation_pct'], 1), 'unit' => '%', 'spec' => $material->spec_min_elongation_pct !== null ? 'min '.$number($material->spec_min_elongation_pct) : null, 'passed' => $meets($kpis['strength']['elongation_pct'], $material->spec_min_elongation_pct)])
                @include('materials._kpi', ['label' => 'Reduction of area (avg)', 'value' => $number($kpis['strength']['reduction_of_area_pct'], 1), 'unit' => '%', 'spec' => null, 'passed' => null])
            </div>
        </div>

        <div class="kpi-group">
            <h3 class="sub-title">Charpy impact{{ $temperature !== null ? ' at '.$number($temperature).' °C' : '' }}</h3>
            <div class="kpi-grid">
                @include('materials._kpi', ['label' => 'Absorbed energy (avg)', 'value' => $number($kpis['charpy']['average_j'], 1), 'unit' => 'J', 'spec' => $material->spec_min_charpy_avg_j !== null ? 'min '.$number($material->spec_min_charpy_avg_j) : null, 'passed' => $meets($kpis['charpy']['average_j'], $material->spec_min_charpy_avg_j)])
                @include('materials._kpi', ['label' => 'Absorbed energy (lowest)', 'value' => $number($kpis['charpy']['minimum_j'], 1), 'unit' => 'J', 'spec' => $material->spec_min_charpy_single_j !== null ? 'min '.$number($material->spec_min_charpy_single_j) : null, 'passed' => $meets($kpis['charpy']['minimum_j'], $material->spec_min_charpy_single_j)])
                @include('materials._kpi', ['label' => 'Lateral expansion (avg)', 'value' => $number($kpis['charpy']['lateral_expansion_mm'], 2), 'unit' => 'mm', 'spec' => null, 'passed' => null])
                @include('materials._kpi', ['label' => 'Shear fracture (avg)', 'value' => $number($kpis['charpy']['shear_pct']), 'unit' => '%', 'spec' => null, 'passed' => null])
            </div>
        </div>

        <div class="kpi-group">
            <h3 class="sub-title">Weldability</h3>
            <div class="kpi-grid">
                @include('materials._kpi', ['label' => 'Carbon equivalent CE (IIW)', 'value' => $number($kpis['weldability']['ce_iiw'], 3), 'unit' => null, 'spec' => 'C + Mn/6 + (Cr+Mo+V)/5 + (Ni+Cu)/15', 'passed' => null])
                @include('materials._kpi', ['label' => 'Pcm', 'value' => $number($kpis['weldability']['pcm'], 3), 'unit' => null, 'spec' => 'Ito-Bessyo, from the heat analysis', 'passed' => null])
            </div>
        </div>
    </section>

    {{-- Composition and chemical analysis --}}
    <section aria-labelledby="chemistry-title">
        <h3 id="chemistry-title" class="sub-title">Composition and chemical analysis <span class="muted small">mass %</span></h3>
        @if ($material->elements->isEmpty())
            <p class="empty">No chemical analysis yet.</p>
        @else
            <div class="card table-scroll">
                <table class="access-table results-table">
                    <thead><tr><th>Element</th><th>Specified</th><th>Measured</th><th><span class="sr-only">Result</span></th></tr></thead>
                    <tbody>
                        @foreach ($material->elements as $element)
                            @php $passed = $element->meetsSpec(); @endphp
                            <tr @class(['is-fail' => $passed === false])>
                                <td><strong>{{ $element->element }}</strong></td>
                                <td>{{ $range($element->spec_min_pct, $element->spec_max_pct, 3) }}</td>
                                <td>{{ $number($element->measured_pct, 3) }}</td>
                                <td>@include('materials._result', ['passed' => $passed])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Tensile tests --}}
    <section aria-labelledby="tensile-title">
        <h3 id="tensile-title" class="sub-title">Tensile tests</h3>
        @if ($material->tensileTests->isEmpty())
            <p class="empty">No tensile tests yet.</p>
        @else
            <div class="card table-scroll">
                <table class="access-table results-table">
                    <thead>
                        <tr>
                            <th>Specimen</th><th>Orientation</th><th>Tested</th>
                            <th>Yield (MPa)<br><span class="muted small">{{ $material->spec_min_yield_mpa !== null ? 'min '.$number($material->spec_min_yield_mpa) : '' }}</span></th>
                            <th>Tensile (MPa)<br><span class="muted small">{{ $range($material->spec_min_uts_mpa, $material->spec_max_uts_mpa) }}</span></th>
                            <th>Elongation (%)<br><span class="muted small">{{ $material->spec_min_elongation_pct !== null ? 'min '.$number($material->spec_min_elongation_pct) : '' }}</span></th>
                            <th>Reduction of area (%)</th>
                            <th><span class="sr-only">Result</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($material->tensileTests as $test)
                            @php $passed = $test->meetsSpec(); @endphp
                            <tr @class(['is-fail' => $passed === false])>
                                <td><strong>{{ $test->specimen }}</strong></td>
                                <td>{{ $test->orientation ?? '—' }}</td>
                                <td>{{ $test->tested_at?->format('j M Y') ?? '—' }}</td>
                                <td @class(['fail-value' => $meets($test->yield_mpa, $material->spec_min_yield_mpa) === false])>{{ $number($test->yield_mpa) }}</td>
                                <td @class(['fail-value' => $meets($test->uts_mpa, $material->spec_min_uts_mpa, $material->spec_max_uts_mpa) === false])>{{ $number($test->uts_mpa) }}</td>
                                <td @class(['fail-value' => $meets($test->elongation_pct, $material->spec_min_elongation_pct) === false])>{{ $number($test->elongation_pct, 1) }}</td>
                                <td>{{ $number($test->reduction_of_area_pct, 1) }}</td>
                                <td>@include('materials._result', ['passed' => $passed])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Charpy impact tests --}}
    <section aria-labelledby="charpy-title">
        <h3 id="charpy-title" class="sub-title">Charpy impact tests</h3>
        @if ($material->charpyTests->isEmpty())
            <p class="empty">No Charpy tests yet.</p>
        @else
            <div class="card table-scroll">
                <table class="access-table results-table">
                    <thead>
                        <tr>
                            <th>Specimen</th><th>Notch</th><th>Temperature (°C)</th>
                            <th>Energy (J)<br><span class="muted small">{{ $material->spec_min_charpy_single_j !== null ? 'min '.$number($material->spec_min_charpy_single_j).' single' : '' }}{{ $material->spec_min_charpy_avg_j !== null ? ' · '.$number($material->spec_min_charpy_avg_j).' avg' : '' }}</span></th>
                            <th>Lateral expansion (mm)</th><th>Shear (%)</th><th>Tested</th>
                            <th><span class="sr-only">Result</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($material->charpyTests as $test)
                            @php $passed = $test->meetsSpec(); @endphp
                            <tr @class(['is-fail' => $passed === false])>
                                <td><strong>{{ $test->specimen }}</strong></td>
                                <td>{{ $test->notch }}</td>
                                <td>{{ $number($test->temperature_c) }}</td>
                                <td @class(['fail-value' => $passed === false])>{{ $number($test->energy_j, 1) }}</td>
                                <td>{{ $number($test->lateral_expansion_mm, 2) }}</td>
                                <td>{{ $number($test->shear_pct) }}</td>
                                <td>{{ $test->tested_at?->format('j M Y') ?? '—' }}</td>
                                <td>@include('materials._result', ['passed' => $passed])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection

@push('scripts')
    @vite('resources/js/material-viewer.js')
@endpush
