<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;

use function Laravel\Prompts\select;

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

        $version = $this->argument('version');

        if ($version === null) {
            $version = $this->input->isInteractive()
                ? select(
                    label: sprintf('Which version of [%s]?', $name),
                    options: array_combine($versions, $versions),
                    default: $versions[count($versions) - 1],
                    scroll: 10,
                )
                : $versions[count($versions) - 1];
        }

        if (! in_array((string) $version, $versions, true)) {
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
            $exitCode = $this->call('modsx:backup', [
                'name' => (string) $name,
                '--quiet-banner' => true,
            ]);

            if ($exitCode !== self::SUCCESS) {
                $this->components->error('Backup of the current state failed - nothing was restored.');

                return self::FAILURE;
            }
        }

        try {
            $result = $manager->restore((string) $name, (string) $version);
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
