<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named set of permissions, given to people directly or through their department.
 */
#[Fillable(['name', 'description', 'permissions'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.people');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_everyone' => 'boolean'];
    }

    /**
     * The built-in tag every person has.
     */
    public static function everyone(): ?self
    {
        return static::where('is_everyone', true)->first();
    }

    /**
     * Tags that can be given to departments and people (all but Everyone).
     *
     * @param  Builder<Tag>  $query
     */
    public function scopeAssignable(Builder $query): void
    {
        $query->where('is_everyone', false);
    }

    public function grants(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? [], true);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->orderBy('name');
    }

    /**
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)->orderBy('name');
    }
}
