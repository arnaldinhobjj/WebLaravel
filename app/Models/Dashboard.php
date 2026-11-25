<?php

namespace App\Models;

use App\Enums\DashboardVisibility;
use App\Enums\DashboardWidgetType;
use App\Enums\WidgetScope;
use Database\Factories\DashboardFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A page of widgets at /dashboard. Personal dashboards belong to one person;
 * shared ones are visible to everyone who may see dashboards.
 */
class Dashboard extends Model
{
    /** @use HasFactory<DashboardFactory> */
    use HasFactory;

    protected $fillable = ['name', 'visibility'];

    protected $attributes = [
        'visibility' => 'personal',
    ];

    /**
     * Lives with people and their actions, see config/fortvale.php.
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
            'visibility' => DashboardVisibility::class,
            'user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<DashboardWidget, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('position')->orderBy('id');
    }

    public function isShared(): bool
    {
        return $this->visibility === DashboardVisibility::Shared;
    }

    public function isOwnedBy(User $person): bool
    {
        return $this->user_id === $person->id;
    }

    /**
     * Shared dashboards plus the person's own personal ones.
     *
     * @param  Builder<Dashboard>  $query
     */
    public function scopeVisibleTo(Builder $query, User $person): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('visibility', DashboardVisibility::Shared)
            ->orWhere('user_id', $person->id));
    }

    /**
     * Adds a widget at the end, or at $position (1 = first) moving the ones
     * from there on down a place.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public function addWidget(DashboardWidgetType $type, WidgetScope $scope = WidgetScope::Everyone, ?int $position = null, ?array $settings = null): DashboardWidget
    {
        return $this->getConnection()->transaction(function () use ($type, $scope, $position, $settings): DashboardWidget {
            $ids = $this->widgets()->pluck('id')->all();
            $index = $position === null ? count($ids) : max(0, min($position - 1, count($ids)));

            $widget = $this->widgets()->create([
                'type' => $type,
                'scope' => $scope,
                'settings' => $settings,
                'position' => $index + 1,
            ]);

            array_splice($ids, $index, 0, [$widget->id]);
            $this->reorderWidgets($ids);

            return $widget;
        });
    }

    /**
     * Puts the widgets in the order of $ids (every widget of this dashboard,
     * each once).
     *
     * @param  list<int>  $ids
     */
    public function reorderWidgets(array $ids): void
    {
        $this->getConnection()->transaction(function () use ($ids): void {
            foreach (array_values($ids) as $index => $id) {
                $this->widgets()->whereKey($id)->update(['position' => $index + 1]);
            }
        });
    }

    /**
     * A sensible starting set: the viewer's own work on a personal dashboard,
     * the whole team's on a shared one.
     */
    public function addRecommendedWidgets(): void
    {
        $scope = $this->isShared() ? WidgetScope::Everyone : WidgetScope::Mine;

        foreach ([
            DashboardWidgetType::TicketSummary,
            DashboardWidgetType::TicketQueue,
            DashboardWidgetType::RecentActivity,
            DashboardWidgetType::TicketsByPriority,
            DashboardWidgetType::TicketsByStatus,
            DashboardWidgetType::ResolvedTrend,
        ] as $type) {
            $this->addWidget($type, $scope);
        }
    }

    /**
     * A personal copy of this dashboard, with the same widgets, for $person to change.
     */
    public function copyFor(User $person, ?string $name = null): self
    {
        return $this->getConnection()->transaction(function () use ($person, $name): self {
            $copy = new self(['name' => $name ?? $this->name, 'visibility' => DashboardVisibility::Personal]);
            $copy->user_id = $person->id;
            $copy->save();

            foreach ($this->widgets as $widget) {
                $copy->widgets()->create($widget->only(['type', 'scope', 'position', 'title', 'size', 'settings']));
            }

            return $copy;
        });
    }
}
