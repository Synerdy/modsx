<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Modsx\BackupRepository;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\ModuleLocator;

class DoctorCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:doctor
                            {--json : Output machine-readable JSON}
                            {--fix : Remove empty module directories}';

    protected $description = 'Check module directories and backups for problems';

    public function handle(ModuleLocator $locator, BackupRepository $backups): int
    {
        $json = (bool) $this->option('json');

        $ambiguous = $this->ambiguousNames($locator);
        $singleForm = $this->singleFormModules($locator);
        $orphaned = $this->orphanedBackups($locator, $backups);
        $prefixCollisions = $locator->prefixCollisions();
        $caseCollisions = $this->caseCollisions($locator, $backups);
        $misnamedMigrations = $locator->misnamedMigrations();
        $brokenBackups = $this->brokenBackups($locator, $backups);
        $foreignPrefix = $this->foreignPrefixBackups($locator, $backups);
        $strayDirectories = $this->strayBackupDirectories($backups);
        $emptyDirectories = $this->emptyDirectories($locator);

        if ($this->option('fix')) {
            $emptyDirectories = $this->removeEmptyDirectories($emptyDirectories);
        }

        $problems = count($ambiguous)
            + count($prefixCollisions)
            + count($caseCollisions)
            + count($brokenBackups);

        if ($json) {
            $this->line((string) json_encode([
                'problems' => $problems,
                'ambiguous_names' => $ambiguous,
                'prefix_collisions' => $prefixCollisions,
                'case_collisions' => $caseCollisions,
                'broken_backups' => $brokenBackups,
                'misnamed_migrations' => $misnamedMigrations,
                'foreign_prefix_backups' => $foreignPrefix,
                'stray_backup_directories' => $strayDirectories,
                'empty_directories' => $emptyDirectories,
                'single_form_modules' => $singleForm,
                'orphaned_backups' => $orphaned,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $problems === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->banner();

        $this->renderAmbiguousNames($locator, $ambiguous);
        $this->renderPrefixCollisions($prefixCollisions);
        $this->renderCaseCollisions($caseCollisions);
        $this->renderBrokenBackups($brokenBackups);
        $this->renderMisnamedMigrations($misnamedMigrations);
        $this->renderForeignPrefixBackups($locator, $foreignPrefix);
        $this->renderStrayDirectories($strayDirectories);
        $this->renderEmptyDirectories($emptyDirectories, (bool) $this->option('fix'));
        $this->renderSingleFormModules($locator, $singleForm);
        $this->renderOrphanedBackups($orphaned);

        $this->newLine();

        if ($problems === 0) {
            $this->components->info('No problems found.');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf('%d problem(s) found.', $problems));

        return self::FAILURE;
    }

    /**
     * Two modules whose names differ only in word boundaries, e.g.
     * "modsx-userprofile" next to "ModsxUserProfile". Both are valid names on
     * their own, so nothing else reports this - but it is almost always one
     * module that was meant to be one module, and will be backed up as two.
     *
     * @return list<array{names: list<string>, paths: array<string, list<string>>}>
     */
    private function ambiguousNames(ModuleLocator $locator): array
    {
        $rows = [];

        foreach ($locator->ambiguousNames() as $group) {
            $rows[] = [
                'names' => array_keys($group),
                'paths' => $group,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{names: list<string>, paths: array<string, list<string>>}>  $ambiguous
     */
    private function renderAmbiguousNames(ModuleLocator $locator, array $ambiguous): void
    {
        foreach ($ambiguous as $group) {
            $names = $group['names'];

            $this->components->error(sprintf(
                'Modules [%s] differ only in word boundaries and are treated as separate modules.',
                implode('] and [', $names)
            ));

            // Careful with the wording: on a case-insensitive filesystem they
            // are not backed up as two at all - they share one tree. Saying
            // "backed up as two" here would be false on Windows and macOS.

            foreach ($group['paths'] as $name => $paths) {
                foreach ($paths as $path) {
                    $this->components->twoColumnDetail($path, '<fg=gray>'.$name.'</>');
                }
            }

            $this->components->bulletList([
                sprintf(
                    'Rename them so both forms come from one name: %s-%s and %s%s.',
                    $locator->prefix(),
                    strtolower(implode('-', preg_split('/(?=[A-Z])/', (string) $names[0], -1, PREG_SPLIT_NO_EMPTY) ?: [])),
                    $locator->prefixStudly(),
                    $names[0],
                ),
            ]);
        }
    }

    /**
     * @param  list<array{owner: string, nested: string}>  $collisions
     */
    private function renderPrefixCollisions(array $collisions): void
    {
        foreach ($collisions as $collision) {
            $this->components->error(sprintf(
                'Module [%s] sits inside the prefix owned by [%s], so their migrations are ambiguous.',
                $collision['nested'],
                $collision['owner'],
            ));

            $this->components->bulletList([
                sprintf(
                    'A migration named after [%s] also reads as belonging to [%s]. Rename one of them.',
                    $collision['nested'],
                    $collision['owner'],
                ),
            ]);
        }
    }

    /**
     * Backup trees whose names differ only in letter case. On Windows and
     * macOS those are one directory, so the two modules share a version
     * sequence and a restore can hand back the wrong module's content.
     *
     * @return list<array{module: string, existing: string}>
     */
    private function caseCollisions(ModuleLocator $locator, BackupRepository $backups): array
    {
        $rows = [];

        foreach (array_unique(array_merge($locator->names(), $backups->modules())) as $module) {
            $existing = $backups->collidingName($module);

            if ($existing !== null) {
                $rows[] = ['module' => $module, 'existing' => $existing];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{module: string, existing: string}>  $collisions
     */
    private function renderCaseCollisions(array $collisions): void
    {
        foreach ($collisions as $collision) {
            $this->components->error(sprintf(
                'Backups for [%s] and [%s] differ only in letter case.',
                $collision['module'],
                $collision['existing'],
            ));

            $this->components->bulletList([
                'On Windows and macOS these are one directory: version numbers interleave '.
                'and a restore can return the other module. Rename one of them.',
            ]);
        }
    }

    /**
     * Versions whose manifest is missing or unreadable. Restore falls back to
     * scanning for module directories without one, but the exact source paths,
     * the file list and the comment are gone.
     *
     * @return list<array{module: string, version: string}>
     */
    private function brokenBackups(ModuleLocator $locator, BackupRepository $backups): array
    {
        $rows = [];

        foreach ($backups->modules() as $module) {
            foreach ($backups->versions($module) as $version) {
                if ($backups->manifest($module, $version) === null) {
                    $rows[] = ['module' => $module, 'version' => $version];
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{module: string, version: string}>  $broken
     */
    private function renderBrokenBackups(array $broken): void
    {
        foreach ($broken as $row) {
            $this->components->error(sprintf(
                'Backup [%s] of module [%s] has no readable %s.',
                $row['version'],
                $row['module'],
                BackupRepository::MANIFEST,
            ));
        }
    }

    /**
     * Informational: versions taken while a different prefix was configured.
     * They still restore, using the paths their manifest recorded.
     *
     * @return list<array{module: string, version: string, prefix: string}>
     */
    private function foreignPrefixBackups(ModuleLocator $locator, BackupRepository $backups): array
    {
        $current = $locator->prefix();
        $rows = [];

        foreach ($backups->modules() as $module) {
            foreach ($backups->versions($module) as $version) {
                $manifest = $backups->manifest($module, $version);
                $prefix = $manifest['prefix'] ?? null;

                if (is_string($prefix) && $prefix !== $current) {
                    $rows[] = ['module' => $module, 'version' => $version, 'prefix' => $prefix];
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{module: string, version: string, prefix: string}>  $rows
     */
    private function renderForeignPrefixBackups(ModuleLocator $locator, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->components->info(sprintf(
            'Backups taken under a different prefix (now "%s"). They still restore:',
            $locator->prefix(),
        ));

        foreach ($rows as $row) {
            $this->components->twoColumnDetail(
                sprintf('%s %s', $row['module'], $row['version']),
                sprintf('<fg=gray>taken as "%s"</>', $row['prefix']),
            );
        }
    }

    /**
     * Informational: directories in a module's backup tree that are not
     * versions. versions() skips anything non-numeric, so these are otherwise
     * invisible - including a version directory renamed by hand.
     *
     * @return list<array{module: string, directory: string}>
     */
    private function strayBackupDirectories(BackupRepository $backups): array
    {
        $rows = [];

        foreach ($backups->modules() as $module) {
            foreach (File::directories($backups->pathFor($module)) as $directory) {
                $basename = basename($directory);

                if (preg_match('/^\d+$/', $basename) !== 1) {
                    $rows[] = ['module' => $module, 'directory' => $basename];
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{module: string, directory: string}>  $rows
     */
    private function renderStrayDirectories(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->components->info('Directories in the backup tree that are not versions:');

        foreach ($rows as $row) {
            $this->components->twoColumnDetail(
                sprintf('%s/%s', $row['module'], $row['directory']),
                '<fg=gray>ignored when listing versions</>',
            );
        }
    }

    /**
     * A directory modsx:scaffold created (or the user did) that ended up with
     * nothing in it. Checked including hidden files, so a deliberate
     * .gitkeep counts as content and is left alone - File::allFiles() ignores
     * dotfiles by default, which would otherwise report .gitkeep's own
     * directory as empty and remove it along with the marker.
     *
     * Informational, not a problem: an empty directory is tidiness, not a
     * defect, and does not affect the exit code.
     *
     * @return list<array{module: string, path: string, removed: bool}>
     */
    private function emptyDirectories(ModuleLocator $locator): array
    {
        $rows = [];

        foreach ($locator->all() as $module => $paths) {
            foreach ($paths as $path) {
                if (File::allFiles(base_path($path), true) === []) {
                    $rows[] = ['module' => $module, 'path' => $path, 'removed' => false];
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{module: string, path: string, removed: bool}>  $rows
     * @return list<array{module: string, path: string, removed: bool}>
     */
    private function removeEmptyDirectories(array $rows): array
    {
        return array_map(function (array $row): array {
            File::deleteDirectory(base_path($row['path']));

            $row['removed'] = true;

            return $row;
        }, $rows);
    }

    /**
     * @param  list<array{module: string, path: string, removed: bool}>  $rows
     */
    private function renderEmptyDirectories(array $rows, bool $fixed): void
    {
        if ($rows === []) {
            return;
        }

        $this->components->info($fixed
            ? sprintf('Removed %d empty module directory(s):', count($rows))
            : 'Empty module directories (nothing was ever put in them, or the last file was removed):');

        foreach ($rows as $row) {
            $this->components->twoColumnDetail($row['path'], '<fg=gray>'.$row['module'].'</>');
        }

        if (! $fixed) {
            $this->components->bulletList(['Run with --fix to remove them.']);
        }
    }

    /**
     * @param  list<array{module: string, path: string, suggestion: string}>  $rows
     */
    private function renderMisnamedMigrations(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->components->info('Migrations that name a module but are not archived with it:');

        foreach ($rows as $row) {
            $this->components->twoColumnDetail($row['path'], '<fg=gray>'.$row['module'].'</>');
            $this->components->bulletList([sprintf('Rename to %s', $row['suggestion'])]);
        }
    }

    /**
     * Informational: a module that exists in only one of the two forms is
     * perfectly legal, but worth seeing at a glance.
     *
     * @return list<array{module: string, kebab: bool, studly: bool}>
     */
    private function singleFormModules(ModuleLocator $locator): array
    {
        $kebabPrefix = $locator->prefix().'-';
        $studlyPrefix = $locator->prefixStudly();

        $rows = [];

        foreach ($locator->all() as $name => $paths) {
            $hasKebab = false;
            $hasStudly = false;

            foreach ($paths as $path) {
                $basename = basename($path);

                $hasKebab = $hasKebab || str_starts_with($basename, $kebabPrefix);
                $hasStudly = $hasStudly || str_starts_with($basename, $studlyPrefix);
            }

            if (! $hasKebab || ! $hasStudly) {
                $rows[] = ['module' => $name, 'kebab' => $hasKebab, 'studly' => $hasStudly];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{module: string, kebab: bool, studly: bool}>  $singleForm
     */
    private function renderSingleFormModules(ModuleLocator $locator, array $singleForm): void
    {
        if ($singleForm === []) {
            return;
        }

        $kebabPrefix = $locator->prefix().'-';
        $studlyPrefix = $locator->prefixStudly();

        $this->components->info('Modules using only one directory form (this is fine, just so you know):');
        $this->table(
            ['Module', $kebabPrefix.'*', $studlyPrefix.'*'],
            array_map(static fn (array $row): array => [
                $row['module'],
                $row['kebab'] ? 'yes' : 'no',
                $row['studly'] ? 'yes' : 'no',
            ], $singleForm),
        );
    }

    /**
     * Backups for modules that no longer exist in the application. Also fine -
     * that is what backups are for - but easy to forget about.
     *
     * @return list<array{module: string, versions: int}>
     */
    private function orphanedBackups(ModuleLocator $locator, BackupRepository $backups): array
    {
        $present = $locator->names();
        $orphans = array_values(array_diff($backups->modules(), $present));

        return array_map(static fn (string $orphan): array => [
            'module' => $orphan,
            'versions' => count($backups->versions($orphan)),
        ], $orphans);
    }

    /**
     * @param  list<array{module: string, versions: int}>  $orphaned
     */
    private function renderOrphanedBackups(array $orphaned): void
    {
        if ($orphaned === []) {
            return;
        }

        $this->components->info('Backups with no matching module in the application:');

        foreach ($orphaned as $orphan) {
            $this->components->twoColumnDetail(
                $orphan['module'],
                sprintf('<fg=gray>%d version(s)</>', $orphan['versions'])
            );
        }
    }
}
