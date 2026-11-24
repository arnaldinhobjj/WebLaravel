<label class="check" data-file-choice="{{ $file->id }}">
    <input type="checkbox" name="stored_files[]" value="{{ $file->id }}" @checked($checked)>
    <span>{{ $file->name }}<small>{{ $file->folder->path() }}</small></span>
</label>
