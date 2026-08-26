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
                            {--m|comment= : Optional note describing this backup}
                            {--quiet-banner : Suppress the banner, for use from other commands}';

    protected $description = 'Copy a module into a new numbered backup version';

    public function handle(ModuleLocator $locator, BackupManager $manager): int
    {
        if (! $this->option('quiet-banner')) {
            $this->banner();
        }

        $name = $this->argument('name') ?? $this->pickModule($locator, 'Which module should be backed up?');

        if ($name === null) {
            return self::FAILURE;
        }

        try {
            $result = $manager->backup((string) $name, $this->option('comment'));
        } catch (ModsxException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Backed up [%s] as version %s.', $name, $result['version']));
        $this->listPaths($result['paths'], 'copied');
        $this->newLine();

        return self::SUCCESS;
    }
}
