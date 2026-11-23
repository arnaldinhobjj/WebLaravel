<?php

namespace App\Services\Workflows;

/**
 * `${{ inputs.camera }}`-style placeholders. Values are substituted as plain
 * text; nothing is ever evaluated as code.
 */
class Expressions
{
    public const PATTERN = '/\$\{\{\s*([a-zA-Z_][a-zA-Z0-9_.-]*)\s*\}\}/';

    public const ROOTS = ['inputs', 'ticket', 'actor', 'steps', 'secrets'];

    /**
     * Every placeholder path used in a value, e.g. ['inputs.camera'].
     *
     * @return list<string>
     */
    public static function references(mixed $value): array
    {
        if (is_array($value)) {
            $found = [];
            foreach ($value as $item) {
                array_push($found, ...self::references($item));
            }

            return $found;
        }

        if (! is_string($value)) {
            return [];
        }

        preg_match_all(self::PATTERN, $value, $matches);

        return $matches[1];
    }

    public static function hasPlaceholder(mixed $value): bool
    {
        return self::references($value) !== [];
    }

    /**
     * Replaces placeholders in a value (recursively for lists and maps).
     *
     * @param  array<string, mixed>  $context
     */
    public static function render(mixed $value, array $context): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => self::render($item, $context), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        return preg_replace_callback(self::PATTERN, function (array $match) use ($context): string {
            $resolved = data_get($context, $match[1]);

            return is_scalar($resolved) ? (string) $resolved : '';
        }, $value);
    }
}
