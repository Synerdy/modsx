<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;
use Modsx\ModuleLocator;

class PathCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:path
                            {name? : Module name; omit for every module}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Show the directories and files that make up a module';

    public function handle(ModuleLocator $locator): int
    {
        $name = $this->argument('name');

        try {
            $directories = $name === null
                ? $locator->all()
                : [(string) $name => $locator->paths((string) $name)];

            $directories = array_filter($directories, static fn (array $paths): bool => $paths !== []);

            $modules = [];

            foreach ($directories as $module => $paths) {
                $modules[$module] = [
                    'directories' => $paths,
                    'files' => $locator->files((string) $module),
                    'migrations' => $locator->migrations((string) $module),
                ];
            }
        } catch (ModsxException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($modules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $modules === [] ? self::FAILURE : self::SUCCESS;
        }

        $this->banner();

        if ($modules === []) {
            $this->components->warn($name === null
                ? 'No modules found in the application.'
                : sprintf('Module [%s] was not found in the application.', $name));

            return self::FAILURE;
        }

        foreach ($modules as $module => $parts) {
            $this->components->info($module);
            $this->listPaths($parts['directories'], 'directory');
            $this->listPaths($parts['files'], 'file');
            $this->listPaths($parts['migrations'], 'migration - archived, never restored');
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
