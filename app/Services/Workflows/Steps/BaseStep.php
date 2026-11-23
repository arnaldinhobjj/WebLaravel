<?php

namespace App\Services\Workflows\Steps;

use App\Services\Workflows\Expressions;

/**
 * Common validation for steps: known `with:` keys, required keys, and
 * fixed-choice values (skipped when a value contains a placeholder, since it
 * is only known when the action runs).
 */
abstract class BaseStep implements Step
{
    /**
     * The `with:` keys this step accepts, mapped to whether they are required.
     *
     * @return array<string, bool>
     */
    abstract protected function keys(): array;

    /**
     * Extra checks for a specific step.
     *
     * @param  array<string, mixed>  $with
     * @return list<string>
     */
    protected function checks(array $with, string $path): array
    {
        return [];
    }

    public function validate(array $with, string $path): array
    {
        $errors = [];

        foreach (array_keys($with) as $key) {
            if (! array_key_exists($key, $this->keys())) {
                $errors[] = "{$path}.{$key}: unknown setting. Allowed: ".implode(', ', array_keys($this->keys())).'.';
            }
        }

        foreach ($this->keys() as $key => $required) {
            if ($required && (! array_key_exists($key, $with) || $with[$key] === null || $with[$key] === '')) {
                $errors[] = "{$path}.{$key}: is required.";
            }
        }

        return [...$errors, ...$this->checks($with, $path)];
    }

    public function redirects(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $with
     * @param  list<string>  $allowed
     * @return list<string>
     */
    protected function oneOf(array $with, string $key, array $allowed, string $path): array
    {
        $value = $with[$key] ?? null;

        if ($value === null || Expressions::hasPlaceholder($value)) {
            return [];
        }

        return in_array($value, $allowed, true) ? [] : ["{$path}.{$key}: must be one of ".implode(', ', $allowed).'.'];
    }

    /**
     * The same check when the action runs, after placeholders are filled in.
     *
     * @param  list<string>  $allowed
     */
    protected function requireOneOf(mixed $value, string $key, array $allowed): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new StepFailed("{$key} \"{$value}\" must be one of ".implode(', ', $allowed).'.');
        }
    }

    /**
     * @param  array<string, mixed>  $with
     * @return list<string>
     */
    protected function plainText(array $with, string $key, string $path, int $max): array
    {
        $value = $with[$key] ?? null;

        if ($value === null) {
            return [];
        }

        if (! is_scalar($value)) {
            return ["{$path}.{$key}: must be text."];
        }

        return mb_strlen((string) $value) > $max ? ["{$path}.{$key}: must be at most {$max} characters."] : [];
    }
}
