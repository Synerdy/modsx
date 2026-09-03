<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;
use Modsx\ModuleDependencies;
use Modsx\ModuleLocator;

/**
 * Which modules a module needs, and how that was worked out.
 *
 * Every edge carries where it came from, because the two sources are not
 * equally strong: one can be pointed at in a file, the other is somebody's
 * word. Showing them the same way would hide which is which.
 */
class DepsCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:deps
                            {name? : Module name; omit for every module}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Show which modules a module needs';

    public function handle(ModuleLocator $locator, ModuleDependencies $dependencies): int
    {
        $json = (bool) $this->option('json');
        $name = $this->argument('name');

        try {
            $graph = $name === null
                ? $dependencies->graph()
                : [(string) $name => $dependencies->for((string) $name)];

            $cycles = $dependencies->cycles();
            $closure = $name === null ? null : $dependencies->closure((string) $name);
        } catch (ModsxException $exception) {
            if ($json) {
                $this->line((string) json_encode(['error' => $exception->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $this->components->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($json) {
            $payload = ['modules' => $graph, 'cycles' => $cycles];

            if ($closure !== null) {
                $payload['closure'] = $closure;
            }

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->banner();

        if ($graph === []) {
            $this->components->warn(sprintf(
                'No modules found. Create a directory named "%s-something" or "%ssomething" to get started.',
                $locator->prefix(),
                $locator->prefixStudly(),
            ));

            return self::SUCCESS;
        }

        foreach ($graph as $module => $edges) {
            $this->components->info((string) $module);

            if ($edges === []) {
                $this->components->twoColumnDetail('<fg=gray>needs nothing else</>', '');

                continue;
            }

            foreach ($edges as $edge) {
                $this->components->twoColumnDetail(
                    $edge['module'],
                    $edge['via'] === 'scan'
                        ? '<fg=green>found in the code</>'
                        : '<fg=yellow>declared in config</>',
                );
            }
        }

        if ($closure !== null && count($closure) > 1) {
            $this->newLine();
            $this->components->info(sprintf(
                'A snapshot of [%s] would hold %d modules: %s',
                $name,
                count($closure),
                implode(', ', $closure),
            ));
        }

        $this->renderCycles($cycles);

        return self::SUCCESS;
    }

    /**
     * @param  list<list<string>>  $cycles
     */
    private function renderCycles(array $cycles): void
    {
        if ($cycles === []) {
            return;
        }

        $this->newLine();
        $this->components->info('Modules that depend on one another:');

        foreach ($cycles as $cycle) {
            $this->components->twoColumnDetail(
                implode(' → ', [...$cycle, $cycle[0]]),
                '<fg=gray>cannot be separated</>',
            );
        }

        // Not a fault, and said so plainly: a ring can be a deliberate design,
        // and the only consequence here is that a snapshot of any one of these
        // holds all of them.
        $this->components->bulletList([
            'A snapshot of any one of these holds all of them, which is what a cycle means in practice.',
        ]);
    }
}
