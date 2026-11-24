@extends('layouts.app')

@section('title', 'Run '.$workflow->name)

@section('content')
    <a href="{{ url()->previous(route('dashboard')) }}" class="back-link"><x-icon name="arrow-left" :size="16" /> Back</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">{{ $workflow->name }}</h2>
            @if ($workflow->description)
                <p class="page-sub">{{ $workflow->description }}</p>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ $formAction }}" class="card ticket-form run-form">
        @csrf

        @foreach ($workflow->inputs() as $name => $input)
            <label class="field">
                <span>{{ $input['description'] }} @unless ($input['required']) <small>(optional)</small> @endunless</span>
                <input type="text" name="inputs[{{ $name }}]" value="{{ old("inputs.$name", $input['default']) }}" maxlength="500"
                       @if ($loop->first) autofocus @endif @if ($input['required']) required @endif
                       @error("inputs.$name") aria-invalid="true" @enderror>
                @error("inputs.$name") <p class="field-error">{{ $message }}</p> @enderror
            </label>
        @endforeach

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><x-icon name="play" :size="16" /> Run</button>
        </div>
    </form>
@endsection
