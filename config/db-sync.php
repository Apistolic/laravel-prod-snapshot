<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Remote Connection
    |--------------------------------------------------------------------------
    |
    | The key of the "connections" array below to use when `db:pull` or
    | `db:push` is run without an explicit connection argument.
    |
    */
    'default' => env('DB_SYNC_CONNECTION', 'prod'),

    /*
    |--------------------------------------------------------------------------
    | Remote Connections
    |--------------------------------------------------------------------------
    |
    | Each entry names a connection defined in config('database.connections')
    | that holds the remote host/database/username/password to dump from
    | (`db:pull`) or restore into (`db:push`) - both commands share this same
    | config. The connection's own "driver" (mysql or pgsql) is used unless
    | overridden here. Set "tunnel.enabled" to open an SSH tunnel before
    | connecting (e.g. Forge servers that only accept DB connections from
    | localhost). Note that `db:push` needs write access, so this
    | connection's credentials can't be the read-only user recommended for
    | `db:pull`-only use - see the README's Security section.
    |
    */
    'connections' => [

        'prod' => [
            'connection' => 'prod',
            'driver' => null, // null = infer from database.connections.prod.driver
            'require_ssl' => false,
            'tunnel' => [
                'enabled' => env('PROD_SSH_HOST', env('PROD_DB_HOST', '')) !== '',
                'ssh_host' => env('PROD_SSH_HOST', env('PROD_DB_HOST', '')),
                'ssh_user' => env('PROD_SSH_USER', 'forge'),
                'ssh_port' => (int) env('PROD_SSH_PORT', 22),
                'local_port' => (int) env('PROD_SSH_LOCAL_PORT', 15432),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Binary Paths
    |--------------------------------------------------------------------------
    |
    | Override these if the client tools aren't on your PATH (e.g. installed
    | via Herd on macOS, where the Postgres resolver below will also try to
    | auto-detect the Herd install if these are left at their defaults).
    |
    */
    'binaries' => [
        'mysqldump' => env('MYSQLDUMP_PATH', 'mysqldump'),
        'mysql' => env('MYSQL_PATH', 'mysql'),
        'pg_dump' => env('PG_DUMP_PATH', 'pg_dump'),
        'psql' => env('PSQL_PATH', 'psql'),
        'pg_restore' => env('PG_RESTORE_PATH', 'pg_restore'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Storage
    |--------------------------------------------------------------------------
    |
    | Directories where dump files are written and later read back from for
    | the local restore.
    |
    */
    'backup_path' => [
        'mysql' => database_path('seeders/mysql-backups'),
        'pgsql' => database_path('seeders/postgres-backups'),
    ],

    /*
    |--------------------------------------------------------------------------
    | After Restore
    |--------------------------------------------------------------------------
    |
    | Artisan commands to run (in order) after a successful local restore,
    | e.g. to reseed a dev admin user. Each entry is passed straight to
    | Artisan::call($command, $arguments).
    |
    | 'after_restore' => [
    |     ['command' => 'db:seed', 'arguments' => ['--class' => \Database\Seeders\DevAdminUserSeeder::class]],
    | ],
    |
    */
    'after_restore' => [],

];
