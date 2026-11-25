<?php

namespace App\Models;

use App\Enums\DashboardWidgetType;
use App\Enums\WidgetScope;
use Database\Factories\DashboardWidgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardWidget extends Model
{
    /** @use HasFactory<DashboardWidgetFactory> */
    use HasFactory;

    protected $fillable = ['type', 'scope', 'position'];

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
}
