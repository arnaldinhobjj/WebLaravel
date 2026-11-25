<?php

namespace App\Http\Requests;

use App\Models\Dashboard;
use App\Models\User;
use App\Services\DashboardTables\TableSources;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for requests that change a dashboard's widgets.
 */
abstract class DashboardWidgetRequest extends FormRequest
{
    /**
     * Only people who may change the dashboard may change its widgets; checked
     * before validation. Someone else's personal dashboard answers 404.
     */
    public function authorize(): bool
    {
        $dashboard = $this->route('dashboard');

        abort_unless($dashboard instanceof Dashboard && $this->user()->can('view', $dashboard), 404);

        return $this->user()->can('update', $dashboard);
    }

    protected function dashboard(): Dashboard
    {
        return $this->route('dashboard');
    }

    protected function editor(): User
    {
        return $this->user();
    }

    protected function tables(): TableSources
    {
        return app(TableSources::class);
    }
}
