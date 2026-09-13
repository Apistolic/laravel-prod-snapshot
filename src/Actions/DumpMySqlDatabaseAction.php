<?php

declare(strict_types=1);

namespace Apistolic\LaravelProdSnapshot\Actions;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class DumpMySqlDatabaseAction
{
    public function execute(string $connection, bool $requireSsl = true, ?string $host = null, ?string $port = null, ?Closure $onOutput = null): string
    {
        $host ??= (string) config("database.connections.{$connection}.host", '');
        $port ??= (string) config("database.connections.{$connection}.port", '3306');
        $database = (string) config("database.connections.{$connection}.database", '');
        $username = (string) config("database.connections.{$connection}.username", '');
        $password = (string) config("database.connections.{$connection}.password", '');

        throw_if($host === '' || $database === '' || $username === '', RuntimeException::class, "The '{$connection}' database connection is missing host/database/username configuration.");

        $dumpDir = (string) config('db-sync.backup_path.mysql', database_path('seeders/mysql-backups'));

        if (! is_dir($dumpDir)) {
            mkdir($dumpDir, 0755, true);
        }

        $dumpPath = $dumpDir.'/dump-'.$connection.'-'.now()->format('YmdHis').'.sql';

        $mysqldumpPath = (string) config('db-sync.binaries.mysqldump', 'mysqldump');
        $args = [
            $mysqldumpPath,
            '--host', $host,
            '--port', $port,
            '--user', $username,
        ];

        if ($requireSsl) {
            $args[] = '--ssl-mode';
            $args[] = 'REQUIRED';
        }

        $args = [...$args, '--single-transaction', '--quick', '--routines', '--triggers', '--result-file', $dumpPath, $database];

        $process = new Process($args);

        if ($password !== '') {
            $process->setEnv(['MYSQL_PWD' => $password] + $process->getEnv());
        }

        $process->setTimeout(null);
        $process->run($onOutput);

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            $error = $error !== '' ? $error : 'Unknown mysqldump error.';

            throw new RuntimeException("mysqldump failed: {$error}");
        }

        return $dumpPath;
    }
}
