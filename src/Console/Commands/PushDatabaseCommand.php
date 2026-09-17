<?php

declare(strict_types=1);

namespace Apistolic\LaravelProdSnapshot\Console\Commands;

use Apistolic\LaravelProdSnapshot\Actions\DumpMySqlDatabaseAction;
use Apistolic\LaravelProdSnapshot\Actions\DumpPostgresDatabaseAction;
use Apistolic\LaravelProdSnapshot\Actions\RestoreMySqlBackupAction;
use Apistolic\LaravelProdSnapshot\Actions\RestorePostgresBackupAction;
use Apistolic\LaravelProdSnapshot\Support\SshTunnel;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The inverse of `db:pull`: dumps your *local* database and restores it into
 * a remote connection - typically used to seed a freshly-provisioned
 * production database from a local working prototype, not to overwrite an
 * already-live one with real data. It still always takes a safety dump of
 * whatever is currently on the remote before overwriting it (unless
 * `--skip-remote-backup` is passed), since a wrong `connection` argument
 * would otherwise be unrecoverable.
 */
final class PushDatabaseCommand extends Command
{
    /** @var string */
    protected $signature = 'db:push
        {connection? : Named connection from config(db-sync.connections) (defaults to db-sync.default)}
        {--dump-only : Dump the local database but do not restore it to the remote}
        {--no-tunnel : Connect directly to the remote DB port instead of the configured SSH tunnel}
        {--skip-remote-backup : Skip taking a safety dump of the remote database before overwriting it (not recommended)}
        {--force : Skip the interactive confirmation prompt}';

    /** @var string */
    protected $description = 'Dump the local database and restore it into a remote connection (e.g. to seed a fresh production database).';

    public function handle(
        DumpMySqlDatabaseAction $dumpMysql,
        DumpPostgresDatabaseAction $dumpPostgres,
        RestoreMySqlBackupAction $restoreMysql,
        RestorePostgresBackupAction $restorePostgres,
    ): int {
        $connectionKey = $this->argument('connection') ?? (string) config('db-sync.default', 'prod');

        $config = config("db-sync.connections.{$connectionKey}");

        if (! is_array($config)) {
            $this->error("No 'db-sync.connections.{$connectionKey}' configuration found. Check config/db-sync.php.");

            return self::FAILURE;
        }

        $connection = (string) ($config['connection'] ?? $connectionKey);
        $driver = $config['driver'] ?? config("database.connections.{$connection}.driver");
        $requireSsl = (bool) ($config['require_ssl'] ?? false);

        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->error("Connection '{$connection}' has an unsupported or missing driver (expected mysql or pgsql, got: ".var_export($driver, true).').');

            return self::FAILURE;
        }

        $host = (string) config("database.connections.{$connection}.host", '');
        $port = (string) config("database.connections.{$connection}.port", $driver === 'mysql' ? '3306' : '5432');
        $database = (string) config("database.connections.{$connection}.database", '');
        $username = (string) config("database.connections.{$connection}.username", '');
        $password = (string) config("database.connections.{$connection}.password", '');

        if ($host === '' || $database === '' || $username === '') {
            $this->error("The '{$connection}' database connection is missing host/database/username configuration.");

            return self::FAILURE;
        }

        $localConnection = (string) config('database.default');
        $localDatabase = (string) config("database.connections.{$localConnection}.database", '');

        if (! $this->confirmPush($connectionKey, $database, $localDatabase)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $tunnelConfig = $config['tunnel'] ?? [];
        $tunnel = null;
        $tunnelEnabled = (bool) ($tunnelConfig['enabled'] ?? false);

        if ($tunnelEnabled && ! $this->option('no-tunnel')) {
            [$tunnel, $host, $port] = $this->openTunnel($tunnelConfig, $host, (int) $port);

            if (! $tunnel instanceof SshTunnel) {
                return self::FAILURE;
            }
        }

        try {
            return $this->dumpAndPush(
                dumpMysql: $dumpMysql,
                dumpPostgres: $dumpPostgres,
                restoreMysql: $restoreMysql,
                restorePostgres: $restorePostgres,
                connection: $connection,
                driver: $driver,
                requireSsl: $requireSsl,
                host: $host,
                port: $port,
                database: $database,
                username: $username,
                password: $password,
            );
        } finally {
            $tunnel?->stop();
        }
    }

    private function confirmPush(string $connectionKey, string $remoteDatabase, string $localDatabase): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $this->line('');
        $this->line("This will overwrite the '{$connectionKey}' database ({$remoteDatabase}) with your local database ({$localDatabase}).");

        if (! $this->option('skip-remote-backup')) {
            $this->line("A safety dump of '{$connectionKey}' will be taken first, so this can be rolled back if needed.");
        } else {
            $this->line('--skip-remote-backup was passed: no rollback dump will be taken before overwriting it.');
        }

        $this->line('');

        $typed = $this->ask("Type the connection name (\"{$connectionKey}\") to confirm");

        return $typed === $connectionKey;
    }

    /**
     * @param  array<string, mixed>  $tunnelConfig
     * @return array{0: ?SshTunnel, 1: string, 2: string}
     */
    private function openTunnel(array $tunnelConfig, string $dbHost, int $dbPort): array
    {
        $sshHost = (string) ($tunnelConfig['ssh_host'] ?? '');
        $sshUser = (string) ($tunnelConfig['ssh_user'] ?? 'forge');
        $sshPort = (int) ($tunnelConfig['ssh_port'] ?? 22);
        $localPort = (int) ($tunnelConfig['local_port'] ?? 15432);

        if ($sshHost === '') {
            $this->error('SSH tunnel requested but no SSH host is configured for this connection.');

            return [null, $dbHost, (string) $dbPort];
        }

        $this->info("Opening SSH tunnel to {$sshUser}@{$sshHost}:{$sshPort} (local port {$localPort})...");

        $tunnel = SshTunnel::forward($sshHost, $sshUser, $sshPort, '127.0.0.1', $dbPort, $localPort);

        try {
            $tunnel->start();
        } catch (RuntimeException $e) {
            $this->error("Failed to open SSH tunnel: {$e->getMessage()}");

            return [null, $dbHost, (string) $dbPort];
        }

        $this->info('Tunnel established.');

        return [$tunnel, '127.0.0.1', (string) $localPort];
    }

    private function dumpAndPush(
        DumpMySqlDatabaseAction $dumpMysql,
        DumpPostgresDatabaseAction $dumpPostgres,
        RestoreMySqlBackupAction $restoreMysql,
        RestorePostgresBackupAction $restorePostgres,
        string $connection,
        string $driver,
        bool $requireSsl,
        string $host,
        string $port,
        string $database,
        string $username,
        string $password,
    ): int {
        $onOutput = function (string $type, string $buffer): void {
            $this->output->write($buffer);
        };

        if (! $this->option('skip-remote-backup')) {
            $this->info("Taking a safety dump of the remote '{$connection}' database before overwriting it...");

            try {
                $backupPath = $driver === 'mysql'
                    ? $dumpMysql->execute(connection: $connection, requireSsl: $requireSsl, host: $host, port: $port, onOutput: $onOutput)
                    : $dumpPostgres->execute(connection: $connection, requireSsl: $requireSsl, host: $host, port: $port, onOutput: $onOutput);
            } catch (RuntimeException $e) {
                $this->error("Safety dump of the remote database failed, aborting before anything was overwritten: {$e->getMessage()}");

                return self::FAILURE;
            }

            $this->info("Safety dump saved to: {$backupPath}");
        }

        $this->info('Dumping local database...');

        $localConnection = (string) config('database.default');

        try {
            $localDumpPath = $driver === 'mysql'
                ? $dumpMysql->execute(connection: $localConnection, requireSsl: false, onOutput: $onOutput)
                : $dumpPostgres->execute(connection: $localConnection, requireSsl: false, onOutput: $onOutput);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Local dump saved to: {$localDumpPath}");

        if ($this->option('dump-only')) {
            return self::SUCCESS;
        }

        $this->info("Restoring local dump to '{$connection}'...");

        try {
            $driver === 'mysql'
                ? $restoreMysql->execute(backupPath: $localDumpPath, host: $host, port: $port, database: $database, username: $username, password: $password)
                : $restorePostgres->execute(backupPath: $localDumpPath, host: $host, port: $port, database: $database, username: $username, password: $password);
        } catch (RuntimeException $e) {
            $this->error("Push failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
