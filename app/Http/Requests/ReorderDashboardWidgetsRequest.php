<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class ReorderDashboardWidgetsRequest extends DashboardWidgetRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * The new order must list exactly this dashboard's widgets.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $given = array_map('intval', (array) $this->input('order'));
                $actual = $this->dashboard()->widgets()->pluck('id')->map(fn ($id): int => (int) $id)->all();
                sort($given);
                sort($actual);

                if ($given !== $actual) {
                    $validator->errors()->add('order', 'The dashboard changed in the meantime. Reload the page and try again.');
                }
            },
        ];
    }

    /**
     * @return list<int>
     */
    public function order(): array
    {
        return array_map('intval', array_values((array) $this->input('order')));
    }
}
