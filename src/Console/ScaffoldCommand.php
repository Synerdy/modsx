<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;
use Modsx\ModuleScaffolder;

/**
 * Create the directory skeleton for a new module.
 *
 * The convention works without this command - that is the whole point of the
 * package - but typing both directory forms by hand is the one way to get it
 * wrong. Here they come from a single name, so they cannot disagree.
 */
class ScaffoldCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:scaffold
                            {name : Module name, in any case; it is normalised}
                            {path?* : Directories to create; omit for the configured list}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Create the directory skeleton for a new module';

    public function handle(ModuleScaffolder $scaffolder): int
    {
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->banner();
        }

        try {
            /** @var list<string> $paths */
            $paths = (array) $this->argument('path');

            $result = $scaffolder->scaffold((string) $this->argument('name'), $paths);
        } catch (ModsxException $exception) {
            if ($json) {
                $this->line((string) json_encode(['error' => $exception->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $this->components->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($json) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($result['created'] === [] && $result['skipped'] === []) {
            $this->components->warn('Nothing to create: modsx.scaffold is empty.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Created %d directory(s) for [%s].',
            count($result['created']),
            $this->argument('name'),
        ));

        $this->listPaths($result['created'], 'created');
        $this->listPaths($result['skipped'], 'already existed');
        $this->newLine();

        // Empty directories are invisible to git, so a skeleton nobody fills in
        // quietly disappears at the next commit. That is the intended
        // behaviour, but it surprises people who expect to see it in a diff.
        $this->components->info('Directories are empty, so git will not track them until you put files in.');
        $this->newLine();

        return self::SUCCESS;
    }
}
