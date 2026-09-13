<?php

declare(strict_types=1);

namespace Apistolic\LaravelProdSnapshot\Console\Commands;

use Apistolic\LaravelProdSnapshot\Actions\DumpMySqlDatabaseAction;
use Apistolic\LaravelProdSnapshot\Actions\DumpPostgresDatabaseAction;
use Apistolic\LaravelProdSnapshot\Actions\RestoreMySqlBackupAction;
use Apistolic\LaravelProdSnapshot\Actions\RestorePostgresBackupAction;
use Apistolic\LaravelProdSnapshot\Support\SshTunnel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

final class PullDatabaseCommand extends Command
{
    /** @var string */
    protected $signature = 'db:pull
        {connection? : Named connection from config(db-sync.connections) (defaults to db-sync.default)}
        {--dump-only : Create the dump file but do not restore it locally}
        {--no-tunnel : Connect directly to the remote DB port instead of the configured SSH tunnel}';

    /** @var string */
    protected $description = 'Dump a remote database and restore it locally (run on your dev machine).';

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

        if ($host === '' || $database === '' || $username === '') {
            $this->error("The '{$connection}' database connection is missing host/database/username configuration.");

            return self::FAILURE;
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
            return $this->dumpAndRestore(
                dumpMysql: $dumpMysql,
                dumpPostgres: $dumpPostgres,
                restoreMysql: $restoreMysql,
                restorePostgres: $restorePostgres,
                connection: $connection,
                driver: $driver,
                requireSsl: $requireSsl,
                host: $host,
                port: $port,
            );
        } finally {
            $tunnel?->stop();
        }
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

    private function dumpAndRestore(
        DumpMySqlDatabaseAction $dumpMysql,
        DumpPostgresDatabaseAction $dumpPostgres,
        RestoreMySqlBackupAction $restoreMysql,
        RestorePostgresBackupAction $restorePostgres,
        string $connection,
        string $driver,
        bool $requireSsl,
        string $host,
        string $port,
    ): int {
        $this->info("Dumping remote database ({$connection})...");

        $onOutput = function (string $type, string $buffer): void {
            $this->output->write($buffer);
        };

        try {
            $dumpPath = $driver === 'mysql'
                ? $dumpMysql->execute(connection: $connection, requireSsl: $requireSsl, host: $host, port: $port, onOutput: $onOutput)
                : $dumpPostgres->execute(connection: $connection, requireSsl: $requireSsl, host: $host, port: $port, onOutput: $onOutput);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            if (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'No such file')) {
                $binary = $driver === 'mysql' ? 'mysqldump' : 'pg_dump';
                $this->line("Ensure the {$binary} client tools are installed and on your PATH, or set it via config/db-sync.php binaries.");
            }

            return self::FAILURE;
        }

        $this->info("Dump saved to: {$dumpPath}");

        if ($this->option('dump-only')) {
            return self::SUCCESS;
        }

        $this->info('Restoring dump to local database...');

        try {
            $driver === 'mysql' ? $restoreMysql->execute() : $restorePostgres->execute();
        } catch (RuntimeException $e) {
            $this->error("Restore failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->runAfterRestoreHooks();

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function runAfterRestoreHooks(): void
    {
        $hooks = config('db-sync.after_restore', []);

        if (! is_array($hooks) || $hooks === []) {
            return;
        }

        foreach ($hooks as $hook) {
            $command = (string) ($hook['command'] ?? '');
            if ($command === '') {
                continue;
            }

            $arguments = $hook['arguments'] ?? [];
            $arguments['--no-interaction'] = true;

            $this->info("Running after-restore hook: {$command}");
            Artisan::call($command, $arguments, $this->output);
        }
    }
}
