<?php

namespace App\Http\Requests;

use App\Enums\ActionType;
use App\Enums\TicketPriority;
use App\Models\Ticket;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveActionRequest extends FormRequest
{
    /**
     * Access is checked by the EnsureCurrentUser middleware on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $isLink = $this->input('type') === ActionType::Link->value;
        $isTicket = $this->input('type') === ActionType::Ticket->value;

        return [
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:160'],
            'type' => ['required', Rule::enum(ActionType::class)],
            'url' => [Rule::requiredIf($isLink), 'nullable', 'string', 'max:2048', $this->linkRule()],
            'ticket_subject' => [Rule::requiredIf($isTicket), 'nullable', 'string', 'max:160'],
            'ticket_category' => [Rule::requiredIf($isTicket), 'nullable', Rule::in(array_keys(Ticket::CATEGORIES))],
            'ticket_priority' => [Rule::requiredIf($isTicket), 'nullable', Rule::enum(TicketPriority::class)],
            'ticket_description' => ['nullable', 'string', 'max:5000'],
            'show_on_home' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => 'Enter the page or website this action opens.',
            'ticket_subject.required' => 'Enter the subject for tickets started from this action.',
        ];
    }

    /**
     * The fields to store for the chosen type; fields of the other type are cleared.
     *
     * @return array<string, mixed>
     */
    public function actionAttributes(): array
    {
        $data = $this->safe()->only(['name', 'description', 'type', 'url', 'ticket_subject', 'ticket_category', 'ticket_priority', 'ticket_description']);
        $data['show_on_home'] = $this->boolean('show_on_home');

        if ($data['type'] === ActionType::Link->value) {
            $data = array_merge($data, ['ticket_subject' => null, 'ticket_category' => null, 'ticket_priority' => null, 'ticket_description' => null]);
        } else {
            $data['url'] = null;
        }

        return $data;
    }

    /**
     * A link is either a path inside Fort Vale ("/ticket") or an http(s) web address.
     */
    private function linkRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $isInternalPath = str_starts_with($value, '/') && ! str_starts_with($value, '//') && ! str_contains($value, '\\');
            $isWebAddress = filter_var($value, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);

            if (! $isInternalPath && ! $isWebAddress) {
                $fail('Enter a path that starts with / (like /ticket) or a web address starting with https://.');
            }
        };
    }
}
