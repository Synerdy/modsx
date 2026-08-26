<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Modsx\BackupManager;
use Modsx\BackupRepository;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;
use Modsx\ModuleLocator;

/**
 * Compare the current state of a module against a version in backup.
 *
 * The comparison is made file by file, using a content hash: a directory that
 * exists on both sides but whose files differ is reported as modified, not as
 * unchanged. Comparing only directory names would report "no changes" for a
 * module whose every file had been rewritten, which is the opposite of what
 * this command is for.
 */
class DiffCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:diff
                            {name? : Module name; omit to pick from a list}
                            {version? : Version to compare against; omit for the newest}
                            {--summary : Show counts only, without listing files}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Compare the current state of a module against a backup version';

    public function handle(ModuleLocator $locator, BackupRepository $backups, BackupManager $manager): int
    {
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->banner();
        }

        $name = $this->argument('name')
            ?? $this->pickBackedUpModule($backups, 'Which module?');

        if ($name === null) {
            return self::FAILURE;
        }

        try {
            $versions = $backups->versions((string) $name);
        } catch (ModsxException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($versions === []) {
            $this->components->error(sprintf('Module [%s] has no backups.', $name));

            return self::FAILURE;
        }

        $version = $this->pickVersion($this->argument('version'), $versions, (string) $name, forceDefault: $json);

        if (! in_array($version, $versions, true)) {
            $this->components->error(sprintf('Version [%s] of module [%s] does not exist.', $version, $name));

            return self::FAILURE;
        }

        try {
            $appPaths = $locator->paths((string) $name);
            $backupPaths = $manager->pathsInBackup((string) $name, $version);
        } catch (ModsxException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $diff = $this->compare(
            $this->fileMap(base_path(), $appPaths),
            $this->fileMap($backups->versionPath((string) $name, $version), $backupPaths),
        );

        $diff['module'] = (string) $name;
        $diff['version'] = $version;
        $diff['identical'] = $diff['added'] === [] && $diff['removed'] === [] && $diff['modified'] === [];

        if ($json) {
            $this->line((string) json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($diff);

        return self::SUCCESS;
    }

    /**
     * Map every file under the given module paths to a content hash.
     *
     * Keys are paths relative to $root, so the two sides of the comparison are
     * directly comparable: a backup stores each directory under the same
     * relative path it has in the application.
     *
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    private function fileMap(string $root, array $paths): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $map = [];

        foreach ($paths as $relative) {
            $directory = $root.'/'.$relative;

            if (! File::isDirectory($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                $absolute = str_replace('\\', '/', $file->getPathname());
                $key = substr($absolute, strlen($root) + 1);

                $map[$key] = (string) md5_file($file->getPathname());
            }
        }

        ksort($map);

        return $map;
    }

    /**
     * @param  array<string, string>  $current
     * @param  array<string, string>  $backup
     * @return array{added: list<string>, removed: list<string>, modified: list<string>, unchanged: int}
     */
    private function compare(array $current, array $backup): array
    {
        $added = [];
        $removed = [];
        $modified = [];
        $unchanged = 0;

        foreach ($current as $path => $hash) {
            if (! array_key_exists($path, $backup)) {
                $added[] = $path;
            } elseif ($backup[$path] !== $hash) {
                $modified[] = $path;
            } else {
                $unchanged++;
            }
        }

        foreach ($backup as $path => $hash) {
            if (! array_key_exists($path, $current)) {
                $removed[] = $path;
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    private function render(array $diff): void
    {
        $this->newLine();

        if ($diff['identical']) {
            $this->components->info(sprintf(
                'Module [%s] is identical to version %s (%d file(s)).',
                $diff['module'],
                $diff['version'],
                $diff['unchanged'],
            ));
            $this->newLine();

            return;
        }

        $this->components->info(sprintf(
            'Comparing [%s] in the application against version %s',
            $diff['module'],
            $diff['version'],
        ));

        $this->components->twoColumnDetail('<fg=green>Added</> (restore would delete)', (string) count($diff['added']));
        $this->components->twoColumnDetail('<fg=yellow>Modified</> (restore would overwrite)', (string) count($diff['modified']));
        $this->components->twoColumnDetail('<fg=red>Removed</> (restore would bring back)', (string) count($diff['removed']));
        $this->components->twoColumnDetail('Unchanged', (string) $diff['unchanged']);

        $this->newLine();

        if ($this->option('summary')) {
            return;
        }

        $this->renderGroup('Added since this version', $diff['added'], 'green', '+');
        $this->renderGroup('Modified since this version', $diff['modified'], 'yellow', '~');
        $this->renderGroup('Missing from the application', $diff['removed'], 'red', '-');
    }

    /**
     * @param  list<string>  $paths
     */
    private function renderGroup(string $heading, array $paths, string $colour, string $marker): void
    {
        if ($paths === []) {
            return;
        }

        $this->line(sprintf(' <fg=%s;options=bold>%s</>', $colour, $heading));

        foreach ($paths as $path) {
            $this->line(sprintf('  <fg=%s>%s</> %s', $colour, $marker, $path));
        }

        $this->newLine();
    }
}
