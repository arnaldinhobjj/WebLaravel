<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database connections per data group
    |--------------------------------------------------------------------------
    |
    | Fort Vale uses more than one database at the same time. People, personal
    | actions and the framework tables (sessions, cache, jobs) live on the
    | default connection (DB_CONNECTION, MySQL by default). Tickets and their
    | activity timeline live on the connection named here (Oracle by default).
    | Point a group at any connection defined in config/database.php.
    |
    */

    'connections' => [
        // Always the default connection. Kept here as well because Laravel
        // switches the default while it runs each migration.
        'people' => env('DB_CONNECTION', 'sqlite'),

        'tickets' => env('TICKETS_DB_CONNECTION', 'oracle'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Actions (YAML, GitHub Actions style)
    |--------------------------------------------------------------------------
    |
    | Shared actions are the .yml files in `path`. `${{ secrets.NAME }}` in a
    | shared action reads FORTVALE_SECRET_NAME from the environment; personal
    | actions can't read secrets. Web requests from actions may only reach
    | public internet addresses unless allow_private_hosts is true.
    |
    */

    'actions' => [
        'path' => base_path('actions'),

        'allow_private_hosts' => (bool) env('FORTVALE_ACTIONS_ALLOW_PRIVATE_HOSTS', false),

        'secrets' => collect(array_merge($_SERVER, $_ENV))
            ->filter(fn (mixed $value, mixed $name): bool => is_string($name) && is_string($value) && str_starts_with($name, 'FORTVALE_SECRET_'))
            ->mapWithKeys(fn (string $value, string $name): array => [substr($name, strlen('FORTVALE_SECRET_')) => $value])
            ->all(),
    ],

    // Web search for AI skills (web.search): duckduckgo (default, no key),
    // brave, tavily or searxng (your own instance).
    'web_search' => [
        'provider' => env('WEB_SEARCH_PROVIDER', 'duckduckgo'),
        'brave_key' => env('BRAVE_SEARCH_API_KEY'),
        'tavily_key' => env('TAVILY_API_KEY'),
        'searxng_url' => env('SEARXNG_URL'),
    ],

];
