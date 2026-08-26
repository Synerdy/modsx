<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\BackupManager;
use Modsx\BackupRepository;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;
use Modsx\ModuleLocator;

class RestoreCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:restore
                            {name? : Module name; omit to pick from a list}
                            {version? : Version to restore; omit for the newest}
                            {--comment= : Optional note for the automatic backup of the current state}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Restore a module from a backup version';

    public function handle(ModuleLocator $locator, BackupRepository $backups, BackupManager $manager): int
    {
        $this->banner();

        $name = $this->argument('name')
            ?? $this->pickBackedUpModule($backups, 'Which module should be restored?');

        if ($name === null) {
            return self::FAILURE;
        }

        try {
            $versions = $backups->versions((string) $name);
        } catch (ModsxException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($versions === []) {
            $this->components->error(sprintf('Module [%s] has no backups.', $name));

            return self::FAILURE;
        }

        $version = $this->pickVersion($this->argument('version'), $versions, (string) $name);

        if (! in_array($version, $versions, true)) {
            $this->components->error(sprintf('Version [%s] of module [%s] does not exist.', $version, $name));

            return self::FAILURE;
        }

        $present = $locator->paths((string) $name);

        if ($present !== []) {
            $this->components->warn('The current state will be backed up, then replaced:');
            $this->listPaths($present, 'will be replaced');
            $this->newLine();
        } else {
            $this->components->info(sprintf(
                'Module [%s] is not in the application - this will install it from backup.',
                $name
            ));
        }

        if (! $this->confirmDestructive(sprintf('Restore [%s] version %s?', $name, $version))) {
            $this->components->info('Nothing was changed.');

            return self::SUCCESS;
        }

        if ($present !== []) {
            $options = ['name' => (string) $name, '--quiet-banner' => true];

            if ($this->option('comment') !== null) {
                $options['--comment'] = $this->option('comment');
            }

            $exitCode = $this->call('modsx:backup', $options);

            if ($exitCode !== self::SUCCESS) {
                $this->components->error('Backup of the current state failed - nothing was restored.');

                return self::FAILURE;
            }
        }

        try {
            $result = $manager->restore((string) $name, $version);
        } catch (ModsxException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Restored [%s] version %s.', $name, $result['version']));
        $this->listPaths($result['paths'], 'restored');
        $this->newLine();

        return self::SUCCESS;
    }
}
