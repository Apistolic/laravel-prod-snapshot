<?php

declare(strict_types=1);

namespace Apistolic\LaravelProdSnapshot\Actions;

use Illuminate\Support\Arr;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class RestoreMySqlBackupAction
{
    /**
     * Restores a MySQL backup file. By default this targets the local
     * `database.default` connection (the `db:pull` use case); pass an
     * explicit `$backupPath` plus `$host`/`$database`/`$username` (and
     * optionally `$port`/`$password`) to restore into an arbitrary remote
     * connection instead (the `db:push` use case) - the underlying restore
     * mechanics are identical either way, only the target differs.
     */
    public function execute(
        ?string $backupDirectory = null,
        ?string $backupPath = null,
        ?string $host = null,
        ?string $port = null,
        ?string $database = null,
        ?string $username = null,
        ?string $password = null,
    ): string {
        $restoringToRemote = $host !== null || $database !== null || $username !== null;

        if (! $restoringToRemote) {
            $connection = config('database.default');
            $driver = config("database.connections.{$connection}.driver");

            throw_if($driver !== 'mysql', RuntimeException::class, "MySQL backup restore seeding requires a 'mysql' connection. Current driver: {$driver}.");

            $host = (string) config("database.connections.{$connection}.host", '127.0.0.1');
            $port = (string) config("database.connections.{$connection}.port", '3306');
            $database = (string) config("database.connections.{$connection}.database");
            $username = (string) config("database.connections.{$connection}.username");
            $password = (string) config("database.connections.{$connection}.password");
        }

        $port ??= '3306';
        $password ??= '';

        if ($backupPath === null) {
            $backupDirectory = $backupDirectory ?: (string) config('db-sync.backup_path.mysql', database_path('seeders/mysql-backups'));
            $backupPath = $this->findLatestBackupPath($backupDirectory);

            throw_if($backupPath === null, RuntimeException::class, "No backup files found in {$backupDirectory}.");
        }

        throw_if(
            $host === null || $host === '' || $database === null || $database === '' || $username === null || $username === '',
            RuntimeException::class,
            'MySQL connection is missing host/database/username configuration.',
        );

        $mysql = (string) config('db-sync.binaries.mysql', 'mysql');
        $ext = $this->normalizedExtension($backupPath);

        $process = match ($ext) {
            'sql' => Process::fromShellCommandline(
                escapeshellarg($mysql).' '
                .'--host '.escapeshellarg($host)
                .' --port '.escapeshellarg($port)
                .' --user '.escapeshellarg($username)
                .' '.escapeshellarg($database)
                .' < '.escapeshellarg($backupPath)
            ),
            'sql.gz' => Process::fromShellCommandline(
                'gzip -dc '.escapeshellarg($backupPath)
                .' | '.escapeshellarg($mysql).' '
                .'--host '.escapeshellarg($host)
                .' --port '.escapeshellarg($port)
                .' --user '.escapeshellarg($username)
                .' '.escapeshellarg($database)
            ),
            default => throw new RuntimeException("Unsupported MySQL backup file type: {$ext} ({$backupPath})."),
        };

        if ($password !== '') {
            $process->setEnv(['MYSQL_PWD' => $password] + $process->getEnv());
        }

        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            $error = $error !== '' ? $error : 'Unknown restore error.';

            throw new RuntimeException("MySQL restore failed: {$error}");
        }

        return $backupPath;
    }

    public function findLatestBackupPath(string $backupDirectory): ?string
    {
        if (! is_dir($backupDirectory)) {
            return null;
        }

        $paths = glob(rtrim($backupDirectory, '/').'/*');
        if (! is_array($paths) || $paths === []) {
            return null;
        }

        $candidates = array_values(array_filter($paths, function (string $path): bool {
            if (! is_file($path)) {
                return false;
            }

            return $this->normalizedExtension($path) !== '';
        }));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return Arr::first($candidates);
    }

    private function normalizedExtension(string $path): string
    {
        $basename = basename($path);

        if (str_ends_with($basename, '.sql.gz')) {
            return 'sql.gz';
        }

        $ext = pathinfo($basename, PATHINFO_EXTENSION);

        $ext = strtolower($ext);

        return match ($ext) {
            'sql' => $ext,
            default => '',
        };
    }
}
