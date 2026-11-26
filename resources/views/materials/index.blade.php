@extends('layouts.app')

@section('title', 'Materials')

@section('search')
    <label class="search">
        <x-icon name="search" :size="16" />
        <span class="sr-only">Search materials</span>
        <input type="search" placeholder="Search materials" autocomplete="off" data-search-input>
        <kbd>Ctrl K</kbd>
    </label>
@endsection

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">Materials <x-icon name="cube" :size="26" class="title-icon" /></h2>
            <p class="page-sub">Each material’s composition, chemical analysis, tensile and Charpy impact tests, checked against its specification.</p>
        </div>
    </div>

    @if ($materials->isEmpty())
        <div class="empty-state">
            <x-icon name="cube" :size="28" />
            <p><strong>No materials yet.</strong></p>
        </div>
    @else
        <section data-search-section aria-label="Materials">
            <div class="card table-scroll">
                <table class="access-table">
                    <thead><tr><th>Code</th><th>Material</th><th>Grade</th><th>Heat</th><th>Tests</th><th>Specification</th></tr></thead>
                    <tbody>
                        @foreach ($materials as $material)
                            <tr data-search-item="{{ $material->code }} {{ $material->name }} {{ $material->part_name }} {{ $material->grade }} {{ $material->heat_number }}">
                                <td><a href="{{ route('materials.show', $material) }}"><strong>{{ $material->code }}</strong></a></td>
                                <td>{{ $material->name }}@if ($material->part_name)<br><span class="muted small">{{ $material->part_name }}</span>@endif</td>
                                <td>{{ $material->grade ?? '—' }}</td>
                                <td>{{ $material->heat_number ?? '—' }}</td>
                                <td class="muted">{{ $material->tensileTests->count() }} tensile · {{ $material->charpyTests->count() }} Charpy</td>
                                <td>@include('materials._verdict', ['verdict' => $verdicts[$material->id]])</td>
                            </tr>
                        @endforeach
                        <tr data-search-empty hidden><td colspan="6" class="muted">No materials match your search.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
