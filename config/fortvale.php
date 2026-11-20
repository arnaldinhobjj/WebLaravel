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

];
