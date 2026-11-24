<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who may use a top-level library folder: a tag or a department, and which of
 * view / upload / edit / delete. A folder without rules is open to everyone
 * with the Files permission.
 */
#[Fillable(['folder_id', 'tag_id', 'department_id', 'abilities'])]
class FolderAccess extends Model
{
    public const ABILITIES = ['view' => 'View', 'upload' => 'Upload', 'edit' => 'Edit', 'delete' => 'Delete'];

    public $timestamps = false;

    protected $table = 'folder_access';

    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.people');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['abilities' => 'array'];
    }

    /**
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
