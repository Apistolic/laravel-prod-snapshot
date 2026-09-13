<?php

declare(strict_types=1);

namespace Apistolic\LaravelProdSnapshot;

use Apistolic\LaravelProdSnapshot\Console\Commands\PullDatabaseCommand;
use Illuminate\Support\ServiceProvider;

final class LaravelProdSnapshotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/db-sync.php', 'db-sync');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/db-sync.php' => config_path('db-sync.php'),
            ], 'db-sync-config');

            $this->commands([
                PullDatabaseCommand::class,
            ]);
        }
    }
}
