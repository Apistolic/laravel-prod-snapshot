# Laravel Prod Snapshot

Sync a MySQL or Postgres database between your local Laravel dev environment
and a remote one — optionally over an SSH tunnel — with `php artisan db:pull`
(remote → local) or `php artisan db:push` (local → remote).

`db:push` is mainly for seeding a freshly-provisioned remote database from a
local working prototype, not for overwriting an already-live database full of
real data — but it still always takes a safety dump of whatever is currently
on the remote before overwriting it, since a wrong `connection` argument
would otherwise be unrecoverable.

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

```bash
php artisan db:push                     # push local db into db-sync.default
php artisan db:push staging             # push into a specific named connection
php artisan db:push --dump-only         # dump the local db but don't push it anywhere
php artisan db:push --no-tunnel         # connect directly, skipping the SSH tunnel
php artisan db:push --skip-remote-backup  # skip the pre-push safety dump of the remote (not recommended)
php artisan db:push --force             # skip the interactive "type the connection name" confirmation
```

`db:push` always asks you to type the connection name back before doing
anything, then (unless `--skip-remote-backup` is passed) dumps the *remote*
database first as a rollback point, then dumps your local database and
restores it over the remote. Use `--force` only in non-interactive contexts
(CI, a scripted provisioning step) where you've already confirmed the target
some other way.

## Security

This package dumps production data to disk and restores it locally, so a few
things are worth being deliberate about:

- **Network exposure.** Prefer the SSH tunnel (`tunnel.enabled => true`) over
  exposing a database port publicly. The tunnel uses key-based auth only
  (`BatchMode=yes`) and only forwards `127.0.0.1` on both ends. Don't widen
  firewall rules (e.g. `0.0.0.0/0`) or disable TLS just to make a connection
  work — fix the tunnel/credentials instead.
- **Transport encryption.** Set `require_ssl => true` for any connection that
  isn't already going through the SSH tunnel (e.g. a direct connection to a
  managed database host). A tunneled connection is already encrypted by SSH,
  so `require_ssl` is typically unnecessary there.
- **Credentials.** For `db:pull`, use a read-only, backup-only database user
  for the remote connection instead of full application credentials, so a
  leaked backup credential can't write or drop data. `db:push` needs a
  credential with write access by definition — if you use the same named
  connection for both commands, that connection's user necessarily has write
  access, so it's worth treating `db:push`'s target credential with extra
  care (rotate it if you suspect it's leaked, don't reuse it elsewhere).
  Treat any credential that's been typed into a terminal or `.env` on a dev
  machine as "seen" and rotate it if that's a concern for your environment.
- **`db:push` overwrites the remote.** Even though it's meant for seeding a
  fresh database rather than clobbering a live one, it will happily overwrite
  whatever is currently there. The interactive confirmation and the automatic
  pre-push backup of the remote both exist because a wrong `connection`
  argument here has no other undo — don't reach for `--force` or
  `--skip-remote-backup` unless you're certain.
- **Local exposure of production data.** A successful `db:pull` puts a full
  copy of production data — including any real user PII — on your local
  machine. Make sure `backup_path` directories are gitignored (this package's
  default paths, `database/seeders/mysql-backups` and
  `database/seeders/postgres-backups`, commonly already are in a Laravel app)
  and delete old dumps you no longer need.
- **Secrets.** Never commit `.env`, and never paste real host/username/password
  values from `config/db-sync.php` or your `.env` into chat, tickets, or docs.

## Requirements

The relevant client binaries must be available: `mysqldump`/`mysql` for MySQL
connections, `pg_dump`/`psql`/`pg_restore` for Postgres. Override their paths
via `config/db-sync.php` (`binaries`) if they're not on your `PATH` — on
macOS with Herd, the Postgres resolver auto-detects Herd's bundled Postgres
install if left at the default.
