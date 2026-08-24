<?php

declare(strict_types=1);

namespace Modsx;

use Illuminate\Support\ServiceProvider;
use Modsx\Console\BackupCommand;
use Modsx\Console\BackupListCommand;
use Modsx\Console\DeleteCommand;
use Modsx\Console\DiffCommand;
use Modsx\Console\DoctorCommand;
use Modsx\Console\InfoCommand;
use Modsx\Console\ListCommand;
use Modsx\Console\PathCommand;
use Modsx\Console\PruneCommand;
use Modsx\Console\RestoreCommand;

class ModsxServiceProvider extends ServiceProvider
{
    public const VERSION = '0.1.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/modsx.php', 'modsx');

        $this->app->singleton(ModuleLocator::class);
        $this->app->singleton(BackupRepository::class);
        $this->app->singleton(BackupManager::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/modsx.php' => config_path('modsx.php'),
        ], 'modsx-config');

        $this->commands([
            BackupCommand::class,
            BackupListCommand::class,
            DeleteCommand::class,
            DiffCommand::class,
            DoctorCommand::class,
            InfoCommand::class,
            ListCommand::class,
            PathCommand::class,
            PruneCommand::class,
            RestoreCommand::class,
        ]);
    }
}
