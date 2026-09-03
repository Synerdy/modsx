<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;
use Modsx\SnapshotRepository;

class SnapshotListCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:snapshotlist
                            {--limit=0 : Show only the newest N snapshots; 0 shows all}
                            {--json : Output machine-readable JSON}';

    protected $description = 'List the snapshots that have been taken';

    public function handle(SnapshotRepository $snapshots): int
    {
        $json = (bool) $this->option('json');
        $limit = max(0, (int) $this->option('limit'));

        try {
            $ids = $snapshots->ids();

            if ($limit > 0) {
                $ids = array_slice($ids, -$limit);
            }

            $rows = [];

            foreach ($ids as $id) {
                $snapshot = $snapshots->read($id);

                if ($snapshot !== null) {
                    $rows[] = $snapshot;
                }
            }

            $dangling = $snapshots->dangling();
        } catch (ModsxException $exception) {
            if ($json) {
                $this->line((string) json_encode(['error' => $exception->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $this->components->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($json) {
            $this->line((string) json_encode(
                ['snapshots' => $rows, 'dangling' => $dangling],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return $rows === [] ? self::FAILURE : self::SUCCESS;
        }

        $this->banner();

        if ($rows === []) {
            $this->components->warn('No snapshots have been taken yet. Run modsx:snapshot to take one.');

            return self::FAILURE;
        }

        $broken = array_unique(array_column($dangling, 'snapshot'));

        $this->table(
            ['Snapshot', 'Created', 'Scope', 'Modules', 'Comment'],
            array_map(static fn (array $row): array => [
                in_array($row['snapshot'], $broken, true)
                    ? sprintf('<fg=red>%s</>', $row['snapshot'])
                    : $row['snapshot'],
                $row['created_at'] ?? '-',
                $row['root'] ?? 'whole project',
                (string) count($row['modules']),
                $row['comment'] ?? '-',
            ], $rows),
        );

        $this->renderDangling($dangling);

        return self::SUCCESS;
    }

    /**
     * @param  list<array{snapshot: string, module: string, version: string}>  $dangling
     */
    private function renderDangling(array $dangling): void
    {
        if ($dangling === []) {
            return;
        }

        $this->components->warn('Some snapshots name versions that are no longer in the backup tree:');

        foreach ($dangling as $row) {
            $this->components->twoColumnDetail(
                sprintf('snapshot %s', $row['snapshot']),
                sprintf('<fg=gray>%s %s is gone</>', $row['module'], $row['version']),
            );
        }

        $this->components->bulletList([
            'Those snapshots can no longer be rolled back to. Only modsx:prune --force removes a held version.',
        ]);
    }
}
