<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\BackupRepository;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\ModuleLocator;

class ListCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modules:list {--json : Output machine-readable JSON}';

    protected $description = 'List the modules present in the application';

    public function handle(ModuleLocator $locator, BackupRepository $backups): int
    {
        $modules = $locator->all();

        if ($this->option('json')) {
            $this->line((string) json_encode($modules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->banner();

        if ($modules === []) {
            $this->components->warn(sprintf(
                'No modules found. Create a directory named "%s-something" or "%ssomething" to get started.',
                $locator->prefix(),
                $locator->prefixStudly(),
            ));

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($modules as $name => $paths) {
            $versions = $backups->versions($name);

            $rows[] = [
                $name,
                (string) count($paths),
                $versions === [] ? '-' : (string) count($versions),
                $versions === [] ? '-' : (string) $backups->latest($name),
            ];
        }

        $this->table(['Module', 'Directories', 'Backups', 'Latest'], $rows);

        return self::SUCCESS;
    }
}
