<?php

declare(strict_types=1);

use Apistolic\LaravelProdSnapshot\Actions\RestorePostgresBackupAction;
use Apistolic\LaravelProdSnapshot\Support\PostgresBinaryPathResolver;

it('selects the most recent postgres backup file in a directory', function (): void {
    $dir = sys_get_temp_dir().'/lps-postgres-backup-test-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    $older = $dir.'/older.sql';
    $newer = $dir.'/newer.sql';

    file_put_contents($older, '-- older');
    file_put_contents($newer, '-- newer');

    touch($older, time() - 120);
    touch($newer, time() - 60);

    $action = new RestorePostgresBackupAction(new PostgresBinaryPathResolver);

    expect($action->findLatestBackupPath($dir))->toBe($newer);
});

it('ignores unsupported files when selecting latest backup', function (): void {
    $dir = sys_get_temp_dir().'/lps-postgres-backup-test-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    $unsupported = $dir.'/notes.txt';
    $backup = $dir.'/dump-forge.sql';

    file_put_contents($unsupported, 'ignore me');
    file_put_contents($backup, '-- sql');

    touch($unsupported, time() - 10);
    touch($backup, time() - 20);

    $action = new RestorePostgresBackupAction(new PostgresBinaryPathResolver);

    expect($action->findLatestBackupPath($dir))->toBe($backup);
});

it('requires a host, database, and username when restoring to a remote connection (db:push)', function (): void {
    $action = new RestorePostgresBackupAction(new PostgresBinaryPathResolver);

    expect(fn () => $action->execute(backupPath: '/tmp/does-not-matter.dump', database: 'prod'))
        ->toThrow(RuntimeException::class, 'Postgres connection is missing host/database/username configuration.');
});
