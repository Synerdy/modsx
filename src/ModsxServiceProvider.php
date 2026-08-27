<?php

declare(strict_types=1);

namespace Modsx;

use Composer\InstalledVersions;
use Illuminate\Support\ServiceProvider;
use Modsx\Console\BackupCommand;
use Modsx\Console\BackupListCommand;
use Modsx\Console\DeleteCommand;
use Modsx\Console\DiffCommand;
use Modsx\Console\DoctorCommand;
use Modsx\Console\ExportCommand;
use Modsx\Console\ImportCommand;
use Modsx\Console\InfoCommand;
use Modsx\Console\ListCommand;
use Modsx\Console\MakeCommand;
use Modsx\Console\PathCommand;
use Modsx\Console\PruneCommand;
use Modsx\Console\RestoreCommand;
use Modsx\Console\ScaffoldCommand;

class ModsxServiceProvider extends ServiceProvider
{
    private const PACKAGE_NAME = 'synerdy/modsx';

    /**
     * The installed package version, read from Composer's own metadata rather
     * than a hand-maintained constant - which drifted out of date at every
     * release before this (v0.2.0 through v0.2.2 all shipped reporting
     * "0.1.0" in the banner and in every backup manifest).
     *
     * Always returned without a leading "v" (Composer reports the git tag
     * verbatim, e.g. "v0.2.3"), so this is a plain semver string wherever it
     * is used - callers that want a "v" prefix, such as the command banner,
     * add their own.
     */
    public static function version(): string
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
            return 'dev';
        }

        $version = InstalledVersions::getPrettyVersion(self::PACKAGE_NAME) ?? 'dev';

        return (string) preg_replace('/^v(?=\d)/i', '', $version);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/modsx.php', 'modsx');

        $this->app->singleton(ModuleLocator::class);
        $this->app->singleton(BackupRepository::class);
        $this->app->singleton(BackupManager::class);
        $this->app->singleton(ModuleDiffer::class);
        $this->app->singleton(ModuleMaker::class);
        $this->app->singleton(ModuleScaffolder::class);
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
            ExportCommand::class,
            ImportCommand::class,
            InfoCommand::class,
            ListCommand::class,
            MakeCommand::class,
            PathCommand::class,
            PruneCommand::class,
            RestoreCommand::class,
            ScaffoldCommand::class,
        ]);
    }
}
