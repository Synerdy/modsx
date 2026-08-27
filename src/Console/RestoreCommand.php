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
                            {--force : Skip the confirmation prompt}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Restore a module from a backup version';

    public function handle(ModuleLocator $locator, BackupRepository $backups, BackupManager $manager): int
    {
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->banner();
        }

        $name = $this->argument('name')
            ?? $this->pickBackedUpModule($backups, 'Which module should be restored?');

        if ($name === null) {
            return self::FAILURE;
        }

        try {
            $versions = $backups->versions((string) $name);
        } catch (ModsxException $exception) {
            return $this->reportFailure($json, $exception->getMessage());
        }

        if ($versions === []) {
            return $this->reportFailure($json, sprintf('Module [%s] has no backups.', $name));
        }

        $version = $this->pickVersion($this->argument('version'), $versions, (string) $name, forceDefault: $json);

        if (! in_array($version, $versions, true)) {
            return $this->reportFailure($json, sprintf('Version [%s] of module [%s] does not exist.', $version, $name));
        }

        $present = $locator->paths((string) $name);

        if (! $json) {
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
        }

        if (! $this->confirmDestructive(sprintf('Restore [%s] version %s?', $name, $version))) {
            if ($json) {
                $this->line((string) json_encode(['changed' => false], JSON_PRETTY_PRINT));
            } else {
                $this->components->info('Nothing was changed.');
            }

            return self::SUCCESS;
        }

        if ($present !== []) {
            $options = ['name' => (string) $name, '--quiet-banner' => true];

            if ($this->option('comment') !== null) {
                $options['--comment'] = $this->option('comment');
            }

            if ($json) {
                $options['--json'] = true;
            }

            $exitCode = $this->call('modsx:backup', $options);

            if ($exitCode !== self::SUCCESS) {
                return $this->reportFailure($json, 'Backup of the current state failed - nothing was restored.');
            }
        }

        try {
            $result = $manager->restore((string) $name, $version);
        } catch (ModsxException $exception) {
            return $this->reportFailure($json, $exception->getMessage());
        }

        if ($json) {
            $this->line((string) json_encode(
                ['module' => (string) $name, 'changed' => true] + $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Restored [%s] version %s.', $name, $result['version']));
        $this->listPaths($result['paths'], 'restored');
        $this->listPaths($result['files'], 'restored');
        $this->newLine();

        return self::SUCCESS;
    }

    private function reportFailure(bool $json, string $message): int
    {
        if ($json) {
            $this->line((string) json_encode(['error' => $message], JSON_PRETTY_PRINT));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
