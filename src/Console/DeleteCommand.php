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
                            {--comment= : Optional note for the automatic backup}
                            {--force : Skip the confirmation prompt}
                            {--skip-backup : Delete without backing up first (dangerous)}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Back up a module, then remove it from the application';

    public function handle(ModuleLocator $locator, BackupManager $manager): int
    {
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->banner();
        }

        $name = $this->argument('name') ?? $this->pickModule($locator, 'Which module should be removed?');

        if ($name === null) {
            return self::FAILURE;
        }

        try {
            $paths = $locator->paths((string) $name);
        } catch (ModsxException $exception) {
            return $this->reportFailure($json, $exception->getMessage());
        }

        if ($paths === []) {
            return $this->reportFailure($json, sprintf('Module [%s] was not found in the application.', $name));
        }

        $files = $locator->files((string) $name);
        $migrations = $locator->migrations((string) $name);

        if (! $json) {
            $this->components->warn(sprintf(
                'These %d paths will be removed:',
                count($paths) + count($files)
            ));
            $this->listPaths($paths, 'will be removed');
            $this->listPaths($files, 'will be removed');
            $this->newLine();

            if ($migrations !== []) {
                // Their tables are still in the database. Deleting the file that
                // documents them would leave the schema with nothing explaining
                // it, so they stay - but silently leaving them would be worse.
                $this->components->warn(sprintf(
                    '%d migration(s) will be left in place, because the tables they created still exist:',
                    count($migrations)
                ));
                $this->listPaths($migrations, 'kept');
                $this->newLine();
            }
        }

        if (! $this->confirmDestructive(sprintf('Remove module [%s]?', $name))) {
            if ($json) {
                $this->line((string) json_encode(['changed' => false], JSON_PRETTY_PRINT));
            } else {
                $this->components->info('Nothing was changed.');
            }

            return self::SUCCESS;
        }

        if (! $this->option('skip-backup')) {
            $options = ['name' => (string) $name, '--quiet-banner' => true];

            if ($this->option('comment') !== null) {
                $options['--comment'] = $this->option('comment');
            }

            if ($json) {
                $options['--json'] = true;
            }

            $exitCode = $this->call('modsx:backup', $options);

            if ($exitCode !== self::SUCCESS) {
                return $this->reportFailure($json, 'Backup failed - the module was left untouched.');
            }
        }

        try {
            $removed = $manager->delete((string) $name);
        } catch (ModsxException $exception) {
            return $this->reportFailure($json, $exception->getMessage());
        }

        if ($json) {
            $this->line((string) json_encode(
                ['module' => (string) $name, 'changed' => true] + $removed,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Removed [%s] from the application.', $name));
        $this->listPaths($removed['paths'], 'removed');
        $this->listPaths($removed['files'], 'removed');
        $this->listPaths($removed['migrations'], 'left in place');
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
