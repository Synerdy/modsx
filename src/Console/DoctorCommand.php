<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\BackupRepository;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\ModuleLocator;

class DoctorCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modules:doctor {--json : Output machine-readable JSON}';

    protected $description = 'Check module directories and backups for problems';

    public function handle(ModuleLocator $locator, BackupRepository $backups): int
    {
        $json = (bool) $this->option('json');

        $ambiguous = $this->ambiguousNames($locator);
        $singleForm = $this->singleFormModules($locator);
        $orphaned = $this->orphanedBackups($locator, $backups);

        $problems = count($ambiguous);

        if ($json) {
            $this->line((string) json_encode([
                'problems' => $problems,
                'ambiguous_names' => $ambiguous,
                'single_form_modules' => $singleForm,
                'orphaned_backups' => $orphaned,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $problems === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->banner();

        $this->renderAmbiguousNames($locator, $ambiguous);
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
