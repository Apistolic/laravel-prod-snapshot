<?php

declare(strict_types=1);

namespace Apistolic\LaravelProdSnapshot\Actions;

use Apistolic\LaravelProdSnapshot\Support\PostgresBinaryPathResolver;
use Illuminate\Support\Arr;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class RestorePostgresBackupAction
{
    public function __construct(
        private PostgresBinaryPathResolver $pgResolver
    ) {}

    /**
     * Restores a Postgres backup file. By default this targets the local
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

            throw_if($driver !== 'pgsql', RuntimeException::class, "Postgres backup restore seeding requires a 'pgsql' connection. Current driver: {$driver}.");

            $host = (string) config("database.connections.{$connection}.host", '127.0.0.1');
            $port = (string) config("database.connections.{$connection}.port", '5432');
            $database = (string) config("database.connections.{$connection}.database");
            $username = (string) config("database.connections.{$connection}.username");
            $password = (string) config("database.connections.{$connection}.password");
        }

        $port ??= '5432';
        $password ??= '';

        if ($backupPath === null) {
            $backupDirectory = $backupDirectory ?: (string) config('db-sync.backup_path.pgsql', database_path('seeders/postgres-backups'));
            $backupPath = $this->findLatestBackupPath($backupDirectory);

            throw_if($backupPath === null, RuntimeException::class, "No backup files found in {$backupDirectory}.");
        }

        throw_if(
            $host === null || $host === '' || $database === null || $database === '' || $username === null || $username === '',
            RuntimeException::class,
            'Postgres connection is missing host/database/username configuration.',
        );

        $ext = $this->normalizedExtension($backupPath);

        $restorePath = $backupPath;
        $tempRestorePath = null;

        if ($ext === 'sql') {
            $tempRestorePath = $this->maybeFilterUnsupportedPsqlMetaCommands($backupPath);
            if ($tempRestorePath !== null) {
                $restorePath = $tempRestorePath;
            }
        }

        $psql = $this->pgResolver->psql();
        $process = match ($ext) {
            'sql' => new Process([
                $psql,
                '--host', $host,
                '--port', $port,
                '--username', $username,
                '--dbname', $database,
                '--file', $restorePath,
                '--single-transaction',
                '--set', 'ON_ERROR_STOP=on',
            ]),
            'sql.gz' => Process::fromShellCommandline(
                'gzip -dc '.escapeshellarg($backupPath)
                .' | '.escapeshellarg($psql).' '
                .'--host '.escapeshellarg($host)
                .' --port '.escapeshellarg($port)
                .' --username '.escapeshellarg($username)
                .' --dbname '.escapeshellarg($database)
                .' --single-transaction --set ON_ERROR_STOP=on'
            ),
            'dump', 'backup', 'dump.gz', 'backup.gz' => $this->buildPgRestoreProcess(
                backupPath: $backupPath,
                ext: $ext,
                host: $host,
                port: $port,
                username: $username,
                database: $database,
                pgRestore: $this->pgResolver->pgRestore(),
            ),
            default => throw new RuntimeException("Unsupported Postgres backup file type: {$ext} ({$backupPath})."),
        };

        if ($password !== '') {
            $process->setEnv(['PGPASSWORD' => $password] + $process->getEnv());
        }

        $process->setTimeout(null);
        $process->run();

        if ($tempRestorePath !== null && is_file($tempRestorePath)) {
            @unlink($tempRestorePath);
        }

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            $output = trim($process->getOutput());

            $message = $error !== '' ? $error : $output;
            $message = $message !== '' ? $message : 'Unknown restore error.';

            throw new RuntimeException("Postgres restore failed: {$message}");
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

    private function maybeFilterUnsupportedPsqlMetaCommands(string $backupPath): ?string
    {
        $handle = fopen($backupPath, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $needsFilter = false;

            for ($i = 0; $i < 100; $i++) {
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }

                if (str_starts_with($line, '\\restrict ')) {
                    $needsFilter = true;
                    break;
                }
            }

            if (! $needsFilter) {
                return null;
            }

            rewind($handle);

            $tempPath = sys_get_temp_dir().'/laravel-prod-snapshot-restore-'.bin2hex(random_bytes(8)).'.sql';
            $out = fopen($tempPath, 'wb');
            if ($out === false) {
                return null;
            }

            try {
                while (($line = fgets($handle)) !== false) {
                    if (str_starts_with($line, '\\restrict ')) {
                        continue;
                    }

                    fwrite($out, $line);
                }
            } finally {
                fclose($out);
            }

            return $tempPath;
        } finally {
            fclose($handle);
        }
    }

    private function normalizedExtension(string $path): string
    {
        $basename = basename($path);

        if (str_ends_with($basename, '.sql.gz')) {
            return 'sql.gz';
        }

        if (str_ends_with($basename, '.dump.gz')) {
            return 'dump.gz';
        }

        if (str_ends_with($basename, '.backup.gz')) {
            return 'backup.gz';
        }

        $ext = pathinfo($basename, PATHINFO_EXTENSION);

        $ext = strtolower($ext);

        return match ($ext) {
            'sql', 'dump', 'backup' => $ext,
            default => '',
        };
    }

    private function buildPgRestoreProcess(
        string $backupPath,
        string $ext,
        string $host,
        string $port,
        string $username,
        string $database,
        string $pgRestore,
    ): Process {
        $args = [
            $pgRestore,
            '--host', $host,
            '--port', $port,
            '--username', $username,
            '--dbname', $database,
            '--no-owner',
            '--no-privileges',
            '--clean',
            '--if-exists',
            '--exit-on-error',
        ];

        if (str_ends_with($ext, '.gz')) {
            return Process::fromShellCommandline(
                'gzip -dc '.escapeshellarg($backupPath)
                .' | '.escapeshellarg($pgRestore).' '
                .'--host '.escapeshellarg($host)
                .' --port '.escapeshellarg($port)
                .' --username '.escapeshellarg($username)
                .' --dbname '.escapeshellarg($database)
                .' --no-owner --no-privileges --clean --if-exists --exit-on-error'
            );
        }

        $args[] = $backupPath;

        return new Process($args);
    }
}
