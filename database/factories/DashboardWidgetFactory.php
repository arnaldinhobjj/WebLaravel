<?php

namespace Database\Factories;

use App\Enums\DashboardWidgetType;
use App\Enums\WidgetScope;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardWidget>
 */
class DashboardWidgetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dashboard_id' => Dashboard::factory(),
            'type' => DashboardWidgetType::TicketSummary,
            'scope' => WidgetScope::Everyone,
            'position' => 1,
        ];
    }
}
