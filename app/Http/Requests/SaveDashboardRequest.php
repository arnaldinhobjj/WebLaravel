<?php

namespace App\Http\Requests;

use App\Enums\DashboardVisibility;
use App\Models\Dashboard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveDashboardRequest extends FormRequest
{
    /**
     * Creating is checked by the route (dashboards.create). Changing a
     * dashboard is checked here, before validation, so nobody learns anything
     * from validation errors about a dashboard they may not change.
     */
    public function authorize(): bool
    {
        $dashboard = $this->route('dashboard');

        if (! $dashboard instanceof Dashboard) {
            return true;
        }

        abort_unless($this->user()->can('view', $dashboard), 404);

        return $this->user()->can('update', $dashboard);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'visibility' => ['required', Rule::enum(DashboardVisibility::class)],
            'start_with' => ['sometimes', Rule::in(['recommended', 'empty'])],
        ];
    }

    /**
     * Sharing with everyone needs "Dashboards: Share dashboards with everyone".
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('visibility') === DashboardVisibility::Shared->value && ! $this->user()->can('dashboards.share')) {
                    $validator->errors()->add('visibility', 'You can’t share dashboards with everyone. Ask someone who manages tags for the Share permission.');
                }
            },
        ];
    }
}
