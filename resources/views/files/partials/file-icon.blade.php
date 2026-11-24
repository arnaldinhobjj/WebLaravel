{{-- A file as a Finder icon: a thumbnail for images, otherwise a page labelled with its type. --}}
@php
    $extension = $file->extension();
    $color = match (true) {
        $file->mime === 'application/pdf' => 'pdf',
        str_starts_with((string) $file->mime, 'image/') => 'image',
        in_array($extension, ['xls', 'xlsx', 'csv', 'ods', 'numbers'], true) => 'sheet',
        in_array($extension, ['doc', 'docx', 'odt', 'pages', 'rtf'], true) => 'doc',
        in_array($extension, ['ppt', 'pptx', 'key', 'odp'], true) => 'slides',
        in_array($extension, ['zip', 'rar', '7z', 'gz', 'tar'], true) => 'archive',
        default => 'plain',
    };
@endphp
@if ($color === 'image' && in_array($file->mime, \App\Services\Files\FileStore::PREVIEWABLE, true) && ! $file->trashed())
    <span class="finder-thumb"><img src="{{ route('files.raw', $file) }}" alt="" loading="lazy"></span>
@else
    <span class="finder-doc finder-doc-{{ $color }}" aria-hidden="true">
        <svg viewBox="0 0 48 60" width="48" height="60"><path d="M4 2h28l14 14v40a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" class="page"/><path d="M32 2v12a2 2 0 0 0 2 2h12" class="fold"/></svg>
        <span class="finder-doc-label">{{ mb_substr(strtoupper($extension ?: 'FILE'), 0, 4) }}</span>
    </span>
@endif
