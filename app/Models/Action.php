<?php

namespace App\Models;

use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowParser;
use Database\Factories\ActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person's own action, written in YAML (GitHub Actions style). The name,
 * show-on-home flag and triggers are copied from the YAML for quick listing.
 */
class Action extends Model
{
    /** @use HasFactory<ActionFactory> */
    use HasFactory;

    protected $fillable = ['name', 'yaml', 'show_on_home', 'triggers'];

    protected $attributes = [
        'show_on_home' => false,
    ];

    private ?Workflow $parsedWorkflow = null;

    /**
     * Always the people connection (MySQL by default), even when loaded through
     * a ticket: Eloquent otherwise reuses the parent model's connection.
     */
    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.people');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_on_home' => 'boolean',
            'triggers' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ActionRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ActionRun::class);
    }

    public function key(): string
    {
        return Workflow::PERSONAL.':'.$this->id;
    }

    /**
     * The parsed YAML. Stored YAML was validated when saved.
     */
    public function workflow(): Workflow
    {
        return $this->parsedWorkflow ??= app(WorkflowParser::class)
            ->parse((string) $this->yaml, Workflow::PERSONAL, $this->key(), $this->user_id);
    }

    /**
     * Copies a validated workflow into this action.
     */
    public function fillFromWorkflow(Workflow $workflow): static
    {
        $this->parsedWorkflow = null;

        return $this->fill([
            'name' => $workflow->name,
            'yaml' => $workflow->yaml,
            'show_on_home' => $workflow->showOnHome(),
            'triggers' => $workflow->triggers(),
        ]);
    }
}
