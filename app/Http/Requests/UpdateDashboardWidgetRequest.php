<?php

namespace App\Http\Requests;

use App\Enums\DashboardWidgetType;
use App\Enums\WidgetScope;
use App\Enums\WidgetSize;
use App\Models\DashboardWidget;
use App\Services\DashboardTables\TableSource;
use Illuminate\Validation\Rule;

/**
 * The settings panel of one widget: title and width for every widget, whose
 * tickets for ticket widgets, and for table widgets the table, columns,
 * filters and number of rows. Every field is optional so the panel can also
 * save just one of them (the width, say). Tables, columns and filter values
 * are limited to what the person saving may use.
 */
class UpdateDashboardWidgetRequest extends DashboardWidgetRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['sometimes', 'nullable', 'string', 'max:60'],
            'size' => ['sometimes', Rule::enum(WidgetSize::class)],
        ];

        if ($this->widget()->type->isAboutTickets()) {
            return [...$rules, 'scope' => ['sometimes', Rule::enum(WidgetScope::class)]];
        }

        $rules['source'] = ['sometimes', 'required', Rule::in(array_keys($this->tables()->availableTo($this->editor())))];
        $rules['limit'] = ['sometimes', 'required', 'integer', Rule::in(TableSource::LIMITS)];

        $source = $this->chosenSource();
        if ($source !== null) {
            $filters = $source->filters($this->editor());
            $rules['columns'] = ['required_with:source', 'array', 'min:1'];
            $rules['columns.*'] = ['string', 'distinct', Rule::in(array_keys($source->columns($this->editor())))];
            $rules['filters'] = ['sometimes', 'array:'.implode(',', array_keys($filters))];
            foreach ($filters as $key => $filter) {
                $rules["filters.{$key}"] = ['nullable', 'string', Rule::in(array_map('strval', array_keys($filter['options'])))];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source.in' => 'Pick a table you have access to.',
            'columns.required_with' => 'Pick at least one column.',
            'columns.min' => 'Pick at least one column.',
            'columns.*.in' => 'Pick columns from the list.',
            'filters.*.in' => 'Pick a filter value from the list.',
            'filters.array' => 'One of the filters isn’t available to you.',
        ];
    }

    /**
     * Back to the customize screen with this widget's settings open.
     */
    protected function getRedirectUrl(): string
    {
        return route('dashboards.edit', [$this->dashboard(), 'widget' => $this->widget()->id]);
    }

    public function widget(): DashboardWidget
    {
        return $this->route('widget');
    }

    /**
     * The table being saved: the one picked in the form, else the widget's own.
     */
    public function chosenSource(): ?TableSource
    {
        if ($this->widget()->type !== DashboardWidgetType::Table) {
            return null;
        }

        return $this->has('source') ? $this->tables()->find((string) $this->input('source')) : $this->widget()->tableSource();
    }
}
