<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;
use Modsx\SnapshotManager;

/**
 * Record where every module stands, all at once.
 *
 * Cheap on purpose. A module that has not changed since its last backup gets
 * no new version, only another reference to the one it already had, so taking
 * a snapshot of an untouched project writes a few hundred bytes and nothing
 * else. A snapshot nobody minds taking is one that will be there when it is
 * needed.
 */
class SnapshotCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:snapshot
                            {name? : Snapshot this module and everything it needs; omit for the whole project}
                            {--comment= : A note recorded with the snapshot}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Record the current version of every module as one snapshot';

    public function handle(SnapshotManager $snapshots): int
    {
        $json = (bool) $this->option('json');
        $name = $this->argument('name');
        $comment = $this->option('comment');

        try {
            $result = $snapshots->take(
                $name === null ? null : (string) $name,
                is_string($comment) && $comment !== '' ? $comment : null,
            );
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

        $this->banner();

        $this->table(
            ['Module', 'Version', ''],
            array_map(fn (string $module, string $version): array => [
                $module,
                $version,
                in_array($module, $result['created'], true)
                    ? '<fg=green>backed up</>'
                    : '<fg=gray>unchanged</>',
            ], array_keys($result['modules']), $result['modules']),
        );

        $this->components->info(sprintf(
            'Snapshot %s taken, holding %d module(s)%s.',
            $result['snapshot'],
            count($result['modules']),
            $result['root'] === null ? '' : sprintf(' around [%s]', $result['root']),
        ));

        if ($result['created'] === []) {
            $this->components->twoColumnDetail(
                '<fg=gray>Nothing had changed, so no new versions were written</>',
                '',
            );
        }

        return self::SUCCESS;
    }
}
