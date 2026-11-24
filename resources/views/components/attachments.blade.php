@props(['type', 'id', 'title' => 'Attachments', 'files' => null])

{{--
    A page's attachments: list, add (if allowed) and remove (if allowed).
    Usage: <x-attachments type="email_template" :id="$template->id" />
    The page type must be listed in App\Support\Attachables.
--}}
@php
    $me = auth()->user();
    $files ??= \App\Support\Attachables::filesFor($type, $id);
    $canAttach = \App\Support\Attachables::canAttach($me, $type, (string) $id);
    $human = fn (int $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : ($bytes >= 1024 ? number_format($bytes / 1024).' KB' : $bytes.' B');
@endphp

@if ($files->isNotEmpty() || $canAttach)
    <section {{ $attributes->class(['card', 'attachments']) }} aria-label="{{ $title }}">
        <h3 class="sub-title">{{ $title }}</h3>
        @if ($files->isNotEmpty())
            <ul class="attachment-list">
                @foreach ($files as $file)
                    <li>
                        <a href="{{ route('files.preview', $file) }}" class="file-name"><x-icon name="list-checks" :size="16" /> {{ $file->name }}</a>
                        <span class="muted small">{{ $human($file->size) }}@if ($file->creator) · {{ $file->creator->name }}@endif</span>
                        <a href="{{ route('files.download', $file) }}" class="link-btn">Download</a>
                        @if (\App\Support\Attachables::canDelete($me, $file))
                            <form method="POST" action="{{ route('files.destroy', $file) }}" data-confirm="Remove “{{ $file->name }}”?">
                                @csrf @method('DELETE')
                                <button type="submit" class="link-btn danger">Remove</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($canAttach)
            <form method="POST" action="{{ route('attachments.store', [$type, $id]) }}" enctype="multipart/form-data" class="drop-zone drop-zone-small" data-drop-zone>
                @csrf
                <x-icon name="plus" :size="16" />
                <label>
                    <span>Drop files or <u>choose files</u> to attach</span>
                    <input type="file" name="files[]" multiple class="sr-only" data-drop-input>
                </label>
                <noscript><button type="submit" class="btn">Attach</button></noscript>
            </form>
            @error('files') <p class="field-error">{{ $message }}</p> @enderror
            @error('files.*') <p class="field-error">{{ $message }}</p> @enderror
        @endif
    </section>
@endif
