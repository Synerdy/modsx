<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\BackupManager;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;
use Modsx\ModuleLocator;

class BackupCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:backup
                            {name? : Module name; omit to pick from a list}
                            {--all : Back up every module in the application}
                            {--m|comment= : Optional note describing this backup}
                            {--skip-unchanged : Do nothing when the module is identical to its newest version}
                            {--json : Output machine-readable JSON}
                            {--quiet-banner : Suppress the banner, for use from other commands}';

    protected $description = 'Copy a module into a new numbered backup version';

    public function handle(ModuleLocator $locator, BackupManager $manager): int
    {
        $json = (bool) $this->option('json');

        if (! $json && ! $this->option('quiet-banner')) {
            $this->banner();
        }

        $names = $this->resolveNames($locator);

        if ($names === null) {
            return self::FAILURE;
        }

        $results = [];

        foreach ($names as $name) {
            try {
                $results[$name] = $manager->backup(
                    $name,
                    $this->option('comment'),
                    (bool) $this->option('skip-unchanged'),
                );
            } catch (ModsxException $exception) {
                return $this->reportFailure($json, $exception->getMessage());
            }
        }

        if ($json) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($results as $name => $result) {
            $this->render((string) $name, $result);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null null when there is nothing to act on
     */
    private function resolveNames(ModuleLocator $locator): ?array
    {
        if ($this->option('all')) {
            $names = $locator->names();

            if ($names === []) {
                $this->components->warn('No modules found in the application.');

                return null;
            }

            return $names;
        }

        $name = $this->argument('name') ?? $this->pickModule($locator, 'Which module should be backed up?');

        return $name === null ? null : [(string) $name];
    }

    /**
     * @param  array{version: string, paths: list<string>, files: list<string>, archived: list<string>, target: string, skipped: bool}  $result
     */
    private function render(string $name, array $result): void
    {
        if ($result['skipped']) {
            $this->components->info(sprintf(
                'Skipped [%s]: identical to version %s.',
                $name,
                $result['version'],
            ));

            return;
        }

        $this->components->info(sprintf('Backed up [%s] as version %s.', $name, $result['version']));
        $this->listPaths($result['paths'], 'copied');
        $this->listPaths($result['files'], 'copied');
        $this->listPaths($result['archived'], 'archived, not restorable');
        $this->newLine();
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
