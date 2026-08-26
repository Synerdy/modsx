<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\BackupManager;
use Modsx\BackupRepository;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;

/**
 * Pack one backup version into a portable .zip, next to the version
 * directory it was built from.
 *
 * The zip is a derived, on-demand artifact, not a new version - versions
 * stay unpacked directories, deliberately, so they can be browsed without
 * extracting anything.
 */
class ExportCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:export
                            {name? : Module name; omit to pick from a list}
                            {version? : Version to export; omit for the newest}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Pack a backup version into a portable .zip';

    public function handle(BackupRepository $backups, BackupManager $manager): int
    {
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->banner();
        }

        $name = $this->argument('name')
            ?? $this->pickBackedUpModule($backups, 'Which module should be exported?');

        if ($name === null) {
            return self::FAILURE;
        }

        try {
            $versions = $backups->versions((string) $name);
        } catch (ModsxException $exception) {
            return $this->reportFailure($json, $exception->getMessage());
        }

        if ($versions === []) {
            return $this->reportFailure($json, sprintf('Module [%s] has no backups.', $name));
        }

        $version = $this->pickVersion($this->argument('version'), $versions, (string) $name, forceDefault: $json);

        if (! in_array($version, $versions, true)) {
            return $this->reportFailure($json, sprintf('Version [%s] of module [%s] does not exist.', $version, $name));
        }

        try {
            $result = $manager->export((string) $name, $version);
        } catch (ModsxException $exception) {
            return $this->reportFailure($json, $exception->getMessage());
        }

        if ($json) {
            $this->line((string) json_encode(
                ['module' => (string) $name] + $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Exported [%s] version %s to %s.',
            $name,
            $result['version'],
            $result['path']
        ));

        return self::SUCCESS;
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
