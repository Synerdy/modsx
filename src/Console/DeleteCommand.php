<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\BackupManager;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;
use Modsx\ModuleLocator;

class DeleteCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:delete
                            {name? : Module name; omit to pick from a list}
                            {--force : Skip the confirmation prompt}
                            {--skip-backup : Delete without backing up first (dangerous)}';

    protected $description = 'Back up a module, then remove it from the application';

    public function handle(ModuleLocator $locator, BackupManager $manager): int
    {
        $this->banner();

        $name = $this->argument('name') ?? $this->pickModule($locator, 'Which module should be removed?');

        if ($name === null) {
            return self::FAILURE;
        }

        try {
            $paths = $locator->paths((string) $name);
        } catch (ModsxException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($paths === []) {
            $this->components->error(sprintf('Module [%s] was not found in the application.', $name));

            return self::FAILURE;
        }

        $this->components->warn(sprintf('These %d directories will be removed:', count($paths)));
        $this->listPaths($paths, 'will be removed');
        $this->newLine();

        if (! $this->confirmDestructive(sprintf('Remove module [%s]?', $name))) {
            $this->components->info('Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('skip-backup')) {
            $exitCode = $this->call('modsx:backup', [
                'name' => (string) $name,
                '--quiet-banner' => true,
            ]);

            if ($exitCode !== self::SUCCESS) {
                $this->components->error('Backup failed - the module was left untouched.');

                return self::FAILURE;
            }
        }

        try {
            $removed = $manager->delete((string) $name);
        } catch (ModsxException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Removed [%s] from the application.', $name));
        $this->listPaths($removed, 'removed');
        $this->newLine();

        return self::SUCCESS;
    }
}
