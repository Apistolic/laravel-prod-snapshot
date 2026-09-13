# Laravel Prod Snapshot

Dump a remote (production) MySQL or Postgres database — optionally over an SSH
tunnel — and restore it into your local Laravel dev database with a single
`php artisan db:pull`.

Extracted from `stolic-league`'s `db:pull` command so it can be reused across
projects.

## Install

Add the repository and require the package:

```bash
composer config repositories.laravel-prod-snapshot vcs https://github.com/Apistolic/laravel-prod-snapshot
composer require apistolic/laravel-prod-snapshot:dev-main
```

(Or, for local development against a checked-out copy on the same machine,
use a `path` repository instead of `vcs` pointing at the package directory.)

Publish the config:

```bash
php artisan vendor:publish --tag=db-sync-config
```

## Configure

Add a database connection for each remote you want to pull from in
`config/database.php` (any name — `prod`, `staging`, etc.), then point at it
from `config/db-sync.php`:

```php
'connections' => [
    'prod' => [
        'connection' => 'prod',      // config('database.connections.prod')
        'driver' => null,            // null = infer from that connection's driver
        'require_ssl' => false,
        'tunnel' => [
            'enabled' => env('PROD_SSH_HOST', '') !== '',
            'ssh_host' => env('PROD_SSH_HOST', ''),
            'ssh_user' => env('PROD_SSH_USER', 'forge'),
            'ssh_port' => (int) env('PROD_SSH_PORT', 22),
            'local_port' => (int) env('PROD_SSH_LOCAL_PORT', 15432),
        ],
    ],
],
```

Set the matching env vars (e.g. `PROD_DB_HOST`, `PROD_DB_DATABASE`,
`PROD_DB_USERNAME`, `PROD_DB_PASSWORD`) and point `database.connections.prod`
at them. The `tunnel` block is optional — omit or disable it for databases
reachable directly (e.g. Laravel Cloud).

Optionally run Artisan commands after a successful restore (e.g. reseed a dev
admin user):

```php
'after_restore' => [
    ['command' => 'db:seed', 'arguments' => ['--class' => \Database\Seeders\DevAdminUserSeeder::class]],
],
```

## Use

```bash
php artisan db:pull                # pull db-sync.default
php artisan db:pull staging        # pull a specific named connection
php artisan db:pull --dump-only    # dump but don't restore locally
php artisan db:pull --no-tunnel    # connect directly, skipping the SSH tunnel
```

MySQL dumps are written to `database_path('seeders/mysql-backups')` and
Postgres dumps to `database_path('seeders/postgres-backups')` (configurable
via `db-sync.backup_path`), then restored into whatever connection
`database.default` points to locally.

## Requirements

The relevant client binaries must be available: `mysqldump`/`mysql` for MySQL
connections, `pg_dump`/`psql`/`pg_restore` for Postgres. Override their paths
via `config/db-sync.php` (`binaries`) if they're not on your `PATH` — on
macOS with Herd, the Postgres resolver auto-detects Herd's bundled Postgres
install if left at the default.
