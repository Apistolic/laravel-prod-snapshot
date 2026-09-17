<?php

declare(strict_types=1);

use Apistolic\LaravelProdSnapshot\Actions\RestoreMySqlBackupAction;

it('returns null when the backup directory does not exist', function () {
    $action = new RestoreMySqlBackupAction;

    expect($action->findLatestBackupPath(sys_get_temp_dir().'/does-not-exist-'.uniqid()))->toBeNull();
});

it('picks the most recently modified backup file', function () {
    $dir = sys_get_temp_dir().'/lps-mysql-'.uniqid();
    mkdir($dir);

    try {
        file_put_contents($dir.'/older.sql', 'old');
        touch($dir.'/older.sql', time() - 100);

        file_put_contents($dir.'/newer.sql', 'new');
        touch($dir.'/newer.sql', time());

        file_put_contents($dir.'/ignored.txt', 'not a backup');

        $action = new RestoreMySqlBackupAction;

        expect($action->findLatestBackupPath($dir))->toBe($dir.'/newer.sql');
    } finally {
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }
});

it('requires a host, database, and username when restoring to a remote connection (db:push)', function (): void {
    $action = new RestoreMySqlBackupAction;

    expect(fn () => $action->execute(backupPath: '/tmp/does-not-matter.sql', database: 'prod'))
        ->toThrow(RuntimeException::class, 'MySQL connection is missing host/database/username configuration.');
});
