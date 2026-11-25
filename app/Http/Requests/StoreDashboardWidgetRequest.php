<?php

namespace App\Http\Requests;

use App\Enums\DashboardWidgetType;
use App\Enums\WidgetScope;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDashboardWidgetRequest extends DashboardWidgetRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DashboardWidgetType::class)],
            'scope' => ['nullable', Rule::enum(WidgetScope::class)],
            'source' => ['nullable', 'string'],
            'position' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Ticket widgets need access to tickets; a table widget needs a table the
     * person may see.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $type = DashboardWidgetType::tryFrom((string) $this->input('type'));
                if ($type === null || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($type->isAboutTickets() && ! $this->editor()->can('tickets.view')) {
                    $validator->errors()->add('type', 'You don’t have access to tickets.');
                }

                if ($type === DashboardWidgetType::Table) {
                    $available = $this->tables()->availableTo($this->editor());
                    if ($available === []) {
                        $validator->errors()->add('type', 'You don’t have access to any tables.');
                    } elseif ($this->filled('source') && ! isset($available[$this->input('source')])) {
                        $validator->errors()->add('source', 'Pick a table you have access to.');
                    }
                }
            },
        ];
    }
}
