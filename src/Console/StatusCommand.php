<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\BackupManager;
use Modsx\BackupRepository;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;
use Modsx\ModuleDiffer;
use Modsx\ModuleLocator;
use Modsx\ModuleState;

/**
 * Where every module stands: what it is, what it came from, what has moved.
 *
 * The shape is deliberately the one people already know from version control.
 * "Current" is the version the working tree came from, so "Changes" counts
 * what has happened since - not the distance to the newest backup, which is a
 * different question and gets its own column. A module can therefore be clean
 * and still be behind, and that combination is the one worth being told about:
 * backing up from an older version builds the next one on top of it.
 */
class StatusCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:status
                            {name? : Module name; omit for every module}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Show the state of every module against its backups';

    public function handle(
        ModuleLocator $locator,
        BackupRepository $backups,
        BackupManager $manager,
        ModuleDiffer $differ,
        ModuleState $state,
    ): int {
        $json = (bool) $this->option('json');
        $name = $this->argument('name');

        try {
            $modules = $name === null
                ? $this->everyModule($locator, $backups)
                : [(string) $name];

            $rows = [];

            foreach ($modules as $module) {
                $rows[$module] = $this->describe($module, $locator, $backups, $manager, $differ, $state);
            }
        } catch (ModsxException $exception) {
            if ($json) {
                $this->line((string) json_encode(['error' => $exception->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $this->components->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($name !== null && $rows[(string) $name]['state'] === 'unknown') {
            $message = sprintf('Module [%s] is not in the application and has no backups.', $name);

            if ($json) {
                $this->line((string) json_encode(['error' => $message], JSON_PRETTY_PRINT));
            } else {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        if ($json) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->banner();
        $this->render($rows, $locator);

        return self::SUCCESS;
    }

    /**
     * Every module the application has, plus every one only the backups have.
     *
     * A module that has been deleted still matters here - it is the one case
     * where the answer is "not where you left it", which no listing built from
     * the application alone can say.
     *
     * @return list<string>
     *
     * @throws ModsxException
     */
    private function everyModule(ModuleLocator $locator, BackupRepository $backups): array
    {
        $names = array_unique(array_merge($locator->names(), $backups->modules()));

        // sort() reindexes, so what array_unique left with gaps is a list again.
        sort($names);

        return $names;
    }

    /**
     * @return array{state: string, current: ?string, latest: ?string, changes: ?int, behind: bool, stale: bool}
     *
     * @throws ModsxException
     */
    private function describe(
        string $module,
        ModuleLocator $locator,
        BackupRepository $backups,
        BackupManager $manager,
        ModuleDiffer $differ,
        ModuleState $state,
    ): array {
        $versions = $backups->versions($module);
        $latest = $backups->latest($module);
        $current = $state->current($module);

        // A pointer at a version that has since been pruned still says where
        // the tree came from, but nothing can be compared against it any more.
        $known = $current !== null && in_array($current, $versions, true);

        if (! $locator->exists($module)) {
            return [
                'state' => $versions === [] ? 'unknown' : 'missing',
                'current' => null,
                'latest' => $latest,
                'changes' => null,
                'behind' => false,
                'stale' => false,
            ];
        }

        if ($versions === []) {
            return [
                'state' => 'untracked',
                'current' => null,
                'latest' => null,
                'changes' => null,
                'behind' => false,
                'stale' => false,
            ];
        }

        $base = $known ? $current : $latest;

        $changes = $this->changesAgainst($module, (string) $base, $locator, $backups, $manager, $differ);

        return [
            'state' => $changes === 0 ? 'clean' : 'modified',
            'current' => $current,
            'latest' => $latest,
            'changes' => $changes,
            'behind' => $known && $current !== $latest,
            'stale' => $current !== null && ! $known,
        ];
    }

    /**
     * How many files differ between the application and one version.
     *
     * @throws ModsxException
     */
    private function changesAgainst(
        string $module,
        string $version,
        ModuleLocator $locator,
        BackupRepository $backups,
        BackupManager $manager,
        ModuleDiffer $differ,
    ): int {
        $diff = $differ->compare(
            $differ->fingerprint(base_path(), $locator->paths($module), $locator->files($module)),
            $differ->fingerprint(
                $backups->versionPath($module, $version),
                $manager->pathsInBackup($module, $version),
                $manager->filesInBackup($module, $version),
            ),
        );

        return count($diff['added']) + count($diff['modified']) + count($diff['removed']);
    }

    /**
     * @param  array<string, array{state: string, current: ?string, latest: ?string, changes: ?int, behind: bool, stale: bool}>  $rows
     */
    private function render(array $rows, ModuleLocator $locator): void
    {
        if ($rows === []) {
            $this->components->warn(sprintf(
                'No modules found. Create a directory named "%s-something" or "%ssomething" to get started.',
                $locator->prefix(),
                $locator->prefixStudly(),
            ));

            return;
        }

        $this->table(
            ['Module', 'State', 'Current', 'Latest backup', 'Changes'],
            array_map(fn (string $module, array $row): array => [
                $module,
                $this->paint($row['state']),
                $row['current'] ?? '-',
                $row['latest'] ?? '-',
                $row['changes'] === null ? '-' : (string) $row['changes'],
            ], array_keys($rows), $rows),
        );

        $this->explain($rows);
    }

    private function paint(string $state): string
    {
        $colour = match ($state) {
            'clean' => 'green',
            'modified' => 'yellow',
            'untracked' => 'blue',
            'missing' => 'red',
            default => 'default',
        };

        return sprintf('<fg=%s>%s</>', $colour, $state);
    }

    /**
     * The two things a row cannot say on its own.
     *
     * A count of 0 next to two different version numbers looks like agreement
     * and is not, and a version number that no longer exists looks like one
     * that does. Both need a sentence.
     *
     * @param  array<string, array{state: string, current: ?string, latest: ?string, changes: ?int, behind: bool, stale: bool}>  $rows
     */
    private function explain(array $rows): void
    {
        foreach ($rows as $module => $row) {
            if ($row['behind']) {
                $this->components->warn(sprintf(
                    '[%s] is working from %s, but %s exists. Backing up now would build the next version on the older one.',
                    $module,
                    $row['current'],
                    $row['latest'],
                ));
            }

            if ($row['stale']) {
                $this->components->warn(sprintf(
                    '[%s] came from version %s, which no longer exists. Counts are measured against %s instead.',
                    $module,
                    $row['current'],
                    $row['latest'],
                ));
            }
        }
    }
}
