<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\BackupRepository;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;

class BackupListCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:backuplist
                            {name? : Module name; omit for every module}
                            {--limit=0 : Show only the newest N versions; 0 shows all}
                            {--json : Output machine-readable JSON}';

    protected $description = 'List available backup versions';

    public function handle(BackupRepository $backups): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $name = $this->argument('name');

        try {
            $modules = $name === null
                ? $backups->modules()
                : [(string) $name];
        } catch (ModsxException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $result = [];

        foreach ($modules as $module) {
            try {
                $versions = $backups->versions($module);
            } catch (ModsxException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }

            if ($versions === []) {
                continue;
            }

            if ($limit > 0) {
                $versions = array_slice($versions, -$limit);
            }

            $result[(string) $module] = array_map(
                static fn (string $version): array => $backups->describe($module, $version),
                $versions,
            );
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result === [] ? self::FAILURE : self::SUCCESS;
        }

        $this->banner();

        if ($result === []) {
            $this->components->warn($name === null
                ? 'No backups found in '.$backups->root().'.'
                : sprintf('Module [%s] has no backups.', $name));

            return self::FAILURE;
        }

        foreach ($result as $module => $versions) {
            $this->components->info($module);

            $this->table(
                ['Version', 'Created', 'Directories', 'Comment'],
                array_map(static fn (array $row): array => [
                    $row['version'],
                    $row['created_at'] ?? '-',
                    (string) $row['paths'],
                    $row['comment'] ?? '-',
                ], $versions),
            );
        }

        return self::SUCCESS;
    }
}
