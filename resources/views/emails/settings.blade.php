@extends('layouts.app')

@section('title', 'Email settings')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">Email settings <x-icon name="settings" :size="26" class="title-icon" /></h2>
            <p class="page-sub">Who Fort Vale’s emails come from, and who else hears about new tickets.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('email-settings.update') }}" class="narrow-form">
        @csrf
        @method('PUT')
        <fieldset class="card" @disabled(! $canEdit)>
            <h3 class="sub-title">Sender</h3>
            <div class="field-row field-row-2">
                <label class="field">
                    <span>Name</span>
                    <input type="text" name="from_name" value="{{ old('from_name', $sender->name) }}" required maxlength="80" @error('from_name') aria-invalid="true" @enderror>
                    @error('from_name') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <label class="field">
                    <span>Address</span>
                    <input type="email" name="from_address" value="{{ old('from_address', $sender->address) }}" required maxlength="255" @error('from_address') aria-invalid="true" @enderror>
                    @error('from_address') <p class="field-error">{{ $message }}</p> @enderror
                </label>
            </div>
            <p class="muted small">Your mail server may only accept some sender addresses. Locally every email lands in Mailpit (http://localhost:8025).</p>
        </fieldset>

        <fieldset class="card" @disabled(! $canEdit)>
            <h3 class="sub-title">Also email about new tickets</h3>
            <p class="muted small">Addresses that get the “New ticket” email for every ticket in a category, separated by commas.</p>
            <div class="field-row field-row-2">
                @foreach (\App\Models\Ticket::CATEGORIES as $category => $label)
                    <label class="field">
                        <span>{{ $label }}</span>
                        <input type="text" name="categories[{{ $category }}]" value="{{ old("categories.{$category}", implode(', ', $categoryRecipients[$category] ?? [])) }}" @error("categories.{$category}") aria-invalid="true" @enderror>
                        @error("categories.{$category}") <p class="field-error">{{ $message }}</p> @enderror
                    </label>
                @endforeach
            </div>
        </fieldset>

        @if ($canEdit)
            <div class="form-actions"><button type="submit" class="btn btn-primary">Save email settings</button></div>
        @endif
    </form>
@endsection
