@extends('layouts.app')

@section('title', 'New dashboard')

@section('content')
    <a href="{{ route('dashboards.index') }}" class="back-link"><x-icon name="arrow-left" :size="16" /> Dashboards</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">New dashboard</h2>
            <p class="page-sub">You can add, remove and reorder widgets afterwards.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('dashboards.store') }}" class="card ticket-form">
        @csrf
        @include('dashboards._fields')

        <fieldset class="field check-list">
            <legend>Start with</legend>
            <label class="check"><input type="radio" name="start_with" value="recommended" @checked(old('start_with', 'recommended') === 'recommended')> Recommended widgets</label>
            <label class="check"><input type="radio" name="start_with" value="empty" @checked(old('start_with') === 'empty')> An empty dashboard</label>
        </fieldset>

        <div class="form-actions">
            <a href="{{ route('dashboards.index') }}" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary">Create dashboard</button>
        </div>
    </form>
@endsection
