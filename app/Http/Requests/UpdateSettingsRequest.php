<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
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
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:80'],
            'username' => [
                'required', 'string', 'min:3', 'max:30',
                'regex:/^[a-z0-9]+([._-][a-z0-9]+)*$/',
                Rule::notIn(User::RESERVED_USERNAMES),
                Rule::unique('users', 'username')->ignore($user),
            ],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'job_title' => ['nullable', 'string', 'max:80'],
            'theme' => ['required', Rule::in(array_keys(User::THEMES))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => 'Use lowercase letters and numbers, separated by single dots, dashes or underscores.',
            'username.not_in' => 'That username is reserved. Please pick another one.',
            'username.unique' => 'Someone already has that username.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['username' => strtolower(trim((string) $this->input('username')))]);
    }
}
