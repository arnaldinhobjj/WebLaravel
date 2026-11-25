<?php

namespace App\Http\Requests;

use App\Enums\DashboardWidgetType;
use App\Enums\WidgetScope;
use App\Models\Dashboard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDashboardWidgetRequest extends FormRequest
{
    /**
     * Only people who may change the dashboard may add to it; checked before
     * validation. Someone else's personal dashboard answers 404.
     */
    public function authorize(): bool
    {
        $dashboard = $this->route('dashboard');

        abort_unless($dashboard instanceof Dashboard && $this->user()->can('view', $dashboard), 404);

        return $this->user()->can('update', $dashboard);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DashboardWidgetType::class)],
            'scope' => ['required', Rule::enum(WidgetScope::class)],
        ];
    }
}
