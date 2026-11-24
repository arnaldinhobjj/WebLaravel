<?php

namespace App\Services\Workflows;

/**
 * Rules for addresses actions may open or call.
 */
class Links
{
    /**
     * A page inside Fort Vale ("/ticket?…") or an http(s) web address. Control
     * characters (newlines, tabs) are never allowed: they break redirects.
     */
    public static function isPageOrWebAddress(string $value): bool
    {
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return false;
        }

        return self::isInternalPath($value) || self::isWebAddress($value);
    }

    public static function isInternalPath(string $value): bool
    {
        return str_starts_with($value, '/')
            && ! str_starts_with($value, '//')
            && ! str_contains($value, '\\')
            && ! preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    public static function isWebAddress(string $value): bool
    {
        return ! preg_match('/[\x00-\x1F\x7F\s]/', $value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
