<?php

namespace App\Services\Workflows;

/**
 * `${{ inputs.camera }}`-style placeholders. Values are substituted as plain
 * text; nothing is ever evaluated as code.
 */
class Expressions
{
    public const PATTERN = '/\$\{\{\s*([a-zA-Z_][a-zA-Z0-9_.-]*)\s*\}\}/';

    public const ROOTS = ['inputs', 'ticket', 'actor', 'steps', 'secrets', 'recipient'];

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
     * Placeholders under `$keepRoots` are left for a later step to fill in,
     * such as `recipient.name`, which the email step fills per recipient.
     *
     * @param  array<string, mixed>  $context
     * @param  list<string>  $keepRoots
     */
    public static function render(mixed $value, array $context, array $keepRoots = []): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => self::render($item, $context, $keepRoots), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        return preg_replace_callback(self::PATTERN, function (array $match) use ($context, $keepRoots): string {
            if (in_array(strtok($match[1], '.'), $keepRoots, true)) {
                return $match[0];
            }

            $resolved = data_get($context, $match[1]);

            return is_scalar($resolved) ? (string) $resolved : '';
        }, $value);
    }
}
