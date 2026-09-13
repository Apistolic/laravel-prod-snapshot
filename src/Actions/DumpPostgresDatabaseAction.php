<?php

declare(strict_types=1);

namespace Apistolic\LaravelProdSnapshot\Actions;

use Apistolic\LaravelProdSnapshot\Support\PostgresBinaryPathResolver;
use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class DumpPostgresDatabaseAction
{
    public function __construct(
        private PostgresBinaryPathResolver $pgResolver
    ) {}

    public function execute(string $connection, bool $requireSsl = true, ?string $host = null, ?string $port = null, ?Closure $onOutput = null): string
    {
        $host ??= (string) config("database.connections.{$connection}.host", '');
        $port ??= (string) config("database.connections.{$connection}.port", '5432');
        $database = (string) config("database.connections.{$connection}.database", '');
        $username = (string) config("database.connections.{$connection}.username", '');
        $password = (string) config("database.connections.{$connection}.password", '');

        throw_if($host === '' || $database === '' || $username === '', RuntimeException::class, "The '{$connection}' database connection is missing host/database/username configuration.");

        $dumpDir = (string) config('db-sync.backup_path.pgsql', database_path('seeders/postgres-backups'));

        if (! is_dir($dumpDir)) {
            mkdir($dumpDir, 0755, true);
        }

        $dumpPath = $dumpDir.'/dump-'.$connection.'-'.now()->format('YmdHis').'.dump';

        $pgDump = $this->pgResolver->pgDump();
        $process = new Process([
            $pgDump,
            '--host', $host,
            '--port', $port,
            '--username', $username,
            '--dbname', $database,
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--file', $dumpPath,
        ]);

        $env = $requireSsl ? ['PGSSLMODE' => 'require'] + $process->getEnv() : $process->getEnv();
        if ($password !== '') {
            $env = ['PGPASSWORD' => $password] + $env;
        }
        $process->setEnv($env);

        $process->setTimeout(null);
        $process->run($onOutput);

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            $error = $error !== '' ? $error : 'Unknown pg_dump error.';

            throw new RuntimeException("pg_dump failed: {$error}");
        }

        return $dumpPath;
    }
}
