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

/**
 * Compare a module against a version in backup, or two versions with each other.
 *
 * The comparison is made file by file, using a content hash: a directory that
 * exists on both sides but whose files differ is reported as modified, not as
 * unchanged. Comparing only directory names would report "no changes" for a
 * module whose every file had been rewritten, which is the opposite of what
 * this command is for.
 *
 * Both modes share one frame: the version named first is the baseline, and
 * what is compared with it is either the application or - when a second
 * version is given - that version. So "modsx:diff Blog 0002" and
 * "modsx:diff Blog 0002 0004" ask the same question from the same starting
 * point, and only the other side moves.
 */
class DiffCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:diff
                            {name? : Module name; omit to pick from a list}
                            {version? : Version to compare against; omit for the newest}
                            {against? : A second version; compares it with the first instead of the application}
                            {--summary : Show counts only, without listing files}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Compare a module against a backup version, or two versions with each other';

    public function handle(ModuleLocator $locator, BackupRepository $backups, BackupManager $manager, ModuleDiffer $differ): int
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

        $argument = $this->argument('against');
        $against = $argument === null ? null : (string) $argument;

        foreach ($against === null ? [$version] : [$version, $against] as $wanted) {
            if (! in_array($wanted, $versions, true)) {
                $this->components->error(sprintf('Version [%s] of module [%s] does not exist.', $wanted, $name));

                return self::FAILURE;
            }
        }

        try {
            $compared = $against === null
                ? $differ->fingerprint(base_path(), $locator->paths((string) $name), $locator->files((string) $name))
                : $this->fingerprintVersion($differ, $backups, $manager, (string) $name, $against);

            $baseline = $this->fingerprintVersion($differ, $backups, $manager, (string) $name, $version);
        } catch (ModsxException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $diff = $differ->compare($compared, $baseline);

        $diff['module'] = (string) $name;

        // Two versions get their own pair of keys rather than reusing "version",
        // so a script reading the JSON can tell the two modes apart by shape.
        if ($against === null) {
            $diff['version'] = $version;
        } else {
            $diff['from'] = $version;
            $diff['to'] = $against;
        }

        $diff['identical'] = $diff['added'] === [] && $diff['removed'] === [] && $diff['modified'] === [];

        if ($json) {
            $this->line((string) json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($diff);

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     *
     * @throws ModsxException
     */
    private function fingerprintVersion(ModuleDiffer $differ, BackupRepository $backups, BackupManager $manager, string $name, string $version): array
    {
        return $differ->fingerprint(
            $backups->versionPath($name, $version),
            $manager->pathsInBackup($name, $version),
            $manager->filesInBackup($name, $version),
        );
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    private function render(array $diff): void
    {
        $this->newLine();

        if ($diff['identical']) {
            $this->components->info(isset($diff['from'])
                ? sprintf(
                    'Versions %s and %s of [%s] are identical (%d file(s)).',
                    $diff['from'],
                    $diff['to'],
                    $diff['module'],
                    $diff['unchanged'],
                )
                : sprintf(
                    'Module [%s] is identical to version %s (%d file(s)).',
                    $diff['module'],
                    $diff['version'],
                    $diff['unchanged'],
                ));
            $this->newLine();

            return;
        }

        $this->components->info(isset($diff['from'])
            ? sprintf('Comparing version %s of [%s] against version %s', $diff['from'], $diff['module'], $diff['to'])
            : sprintf('Comparing [%s] in the application against version %s', $diff['module'], $diff['version']));

        $captions = $this->captions($diff);

        $this->components->twoColumnDetail('<fg=green>Added</> '.$captions['added'][0], (string) count($diff['added']));
        $this->components->twoColumnDetail('<fg=yellow>Modified</> '.$captions['modified'][0], (string) count($diff['modified']));
        $this->components->twoColumnDetail('<fg=red>Removed</> '.$captions['removed'][0], (string) count($diff['removed']));
        $this->components->twoColumnDetail('Unchanged', (string) $diff['unchanged']);

        $this->newLine();

        if ($this->option('summary')) {
            return;
        }

        $this->renderGroup($captions['added'][1], $diff['added'], 'green', '+');
        $this->renderGroup($captions['modified'][1], $diff['modified'], 'yellow', '~');
        $this->renderGroup($captions['removed'][1], $diff['removed'], 'red', '-');
    }

    /**
     * Wording for the three groups, as a summary suffix and a listing heading.
     *
     * The two modes need different words for the same three numbers: against
     * the application the useful frame is what a restore would do next, while
     * between two versions no restore is in sight - only what changed. Saying
     * "restore would delete" there would describe an action nobody asked for.
     *
     * @param  array<string, mixed>  $diff
     * @return array{added: array{string, string}, modified: array{string, string}, removed: array{string, string}}
     */
    private function captions(array $diff): array
    {
        if (isset($diff['from'])) {
            return [
                'added' => [sprintf('(only in %s)', $diff['to']), sprintf('Added in %s', $diff['to'])],
                'modified' => ['(differs between the two)', 'Changed between the two versions'],
                'removed' => [sprintf('(only in %s)', $diff['from']), sprintf('Gone after %s', $diff['from'])],
            ];
        }

        return [
            'added' => ['(restore would delete)', 'Added since this version'],
            'modified' => ['(restore would overwrite)', 'Modified since this version'],
            'removed' => ['(restore would bring back)', 'Missing from the application'],
        ];
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
