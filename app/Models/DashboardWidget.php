<?php

namespace App\Models;

use App\Enums\DashboardWidgetType;
use App\Enums\WidgetScope;
use App\Enums\WidgetSize;
use App\Services\DashboardTables\TableSource;
use App\Services\DashboardTables\TableSources;
use Database\Factories\DashboardWidgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardWidget extends Model
{
    /** @use HasFactory<DashboardWidgetFactory> */
    use HasFactory;

    protected $fillable = ['type', 'scope', 'position', 'title', 'size', 'settings'];

    /**
     * Stored next to its dashboard, see config/fortvale.php.
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
            'type' => DashboardWidgetType::class,
            'scope' => WidgetScope::class,
            'size' => WidgetSize::class,
            'settings' => 'array',
            'position' => 'integer',
            'dashboard_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Dashboard, $this>
     */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /** The title on the dashboard: the one given on the customize screen, or the widget's (or its table's) name. */
    public function displayTitle(): string
    {
        if (filled($this->title)) {
            return $this->title;
        }

        return $this->tableSource()?->label() ?? $this->type->label();
    }

    /** Full width or half: as resized on the customize screen, else the widget's usual width. */
    public function isWide(): bool
    {
        return $this->size ? $this->size === WidgetSize::Wide : $this->type->isWide();
    }

    /** The table a table widget shows; null for other widgets. */
    public function tableSource(): ?TableSource
    {
        return $this->type === DashboardWidgetType::Table
            ? app(TableSources::class)->find($this->settings['source'] ?? null)
            : null;
    }
}
