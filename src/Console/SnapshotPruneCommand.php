<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\Console\Concerns\ConfirmsDestructiveActions;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;
use Modsx\SnapshotManager;
use Modsx\SnapshotRepository;

/**
 * Let go of old snapshots.
 *
 * This exists because snapshots hold versions back from modsx:prune. Without
 * a way to release one, the backup tree could only ever grow, and a safeguard
 * you cannot undo stops being a safeguard.
 *
 * Removing a snapshot removes no versions. It only stops them being held, so
 * the next modsx:prune can consider them again.
 */
class SnapshotPruneCommand extends Command
{
    use ConfirmsDestructiveActions;
    use InteractsWithModules;

    protected $signature = 'modsx:snapshotprune
                            {--keep=10 : Number of newest snapshots to keep}
                            {--dry-run : Show what would be removed, and remove nothing}
                            {--force : Skip the confirmation prompt}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Remove old snapshots, keeping the newest';

    public function handle(SnapshotManager $manager, SnapshotRepository $snapshots): int
    {
        $json = (bool) $this->option('json');
        $keep = max(1, (int) $this->option('keep'));
        $dryRun = (bool) $this->option('dry-run');

        try {
            $plan = $manager->prune($keep, dryRun: true);
        } catch (ModsxException $exception) {
            if ($json) {
                $this->line((string) json_encode(['error' => $exception->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $this->components->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($json) {
            // Gated the same way the rendered path is. Asking for
            // machine-readable output is not the same as saying yes, so a
            // script still has to pass --force.
            if (! $dryRun && $plan !== [] && ! $this->confirmDestructive(
                sprintf('Remove %d snapshot(s)?', count($plan))
            )) {
                $this->line((string) json_encode([
                    'dry_run' => false,
                    'keep' => $keep,
                    'total' => 0,
                    'removed' => [],
                ], JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            if (! $dryRun && $plan !== []) {
                $manager->prune($keep);
            }

            $this->line((string) json_encode([
                'dry_run' => $dryRun,
                'keep' => $keep,
                'total' => count($plan),
                $dryRun ? 'plan' : 'removed' => $plan,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->banner();

        if ($plan === []) {
            $this->components->info(sprintf(
                'Nothing to remove: %d snapshot(s), keeping %d.',
                count($snapshots->ids()),
                $keep,
            ));

            return self::SUCCESS;
        }

        foreach ($plan as $id) {
            $snapshot = $snapshots->read($id);

            $this->components->twoColumnDetail(
                sprintf('snapshot %s', $id),
                sprintf('<fg=gray>%s</>', $snapshot['comment'] ?? ($snapshot['created_at'] ?? '')),
            );
        }

        if ($dryRun) {
            $this->components->info(sprintf('%d snapshot(s) would be removed.', count($plan)));

            return self::SUCCESS;
        }

        if (! $this->confirmDestructive(sprintf('Remove %d snapshot(s)?', count($plan)))) {
            return self::SUCCESS;
        }

        $removed = $manager->prune($keep);

        $this->components->info(sprintf(
            '%d snapshot(s) removed. No versions were deleted; they are simply no longer held.',
            count($removed),
        ));

        return self::SUCCESS;
    }
}
