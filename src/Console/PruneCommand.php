<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;

use function Laravel\Prompts\multiselect;

use Modsx\BackupManager;
use Modsx\BackupRepository;
use Modsx\Console\Concerns\ConfirmsDestructiveActions;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;

class PruneCommand extends Command
{
    use ConfirmsDestructiveActions;
    use InteractsWithModules;

    protected $signature = 'modsx:prune
                            {name? : Module name; omit for every module with backups}
                            {--keep= : How many of the newest versions to keep}
                            {--duplicates : Remove versions identical to the one after them, ignoring --keep}
                            {--with-comments : In --duplicates, remove a commented version without asking}
                            {--dry-run : Show what would be removed and stop}
                            {--force : Skip the confirmation prompt}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Remove old backup versions, keeping the newest ones';

    public function handle(BackupRepository $backups, BackupManager $manager): int
    {
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->banner();
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('duplicates')) {
            return $this->handleDuplicates($backups, $manager, $json, $dryRun);
        }

        $keep = max(1, (int) ($this->option('keep') ?? config('modsx.prune.keep', 5)));

        $name = $this->argument('name');
        $modules = $name === null ? $backups->modules() : [(string) $name];

        if ($modules === []) {
            return $this->nothingToPrune($json, 'No backups found in '.$backups->root().'.', warn: true);
        }

        $plan = [];
        $held = [];

        foreach ($modules as $module) {
            try {
                $plan[(string) $module] = $manager->prune($module, $keep, dryRun: true);
                $holding = $manager->heldFromPrune($module, $keep);
            } catch (ModsxException $exception) {
                return $this->reportFailure($json, $exception->getMessage());
            }

            if ($holding !== []) {
                $held[(string) $module] = $holding;
            }
        }

        $plan = array_filter($plan, static fn (array $versions): bool => $versions !== []);

        if ($plan === []) {
            // Two different reasons to have nothing to do, and saying the wrong
            // one is worse than saying nothing: "every module has few enough
            // versions" is plainly false when the old ones are simply held.
            return $this->nothingToPrune($json, $held === []
                ? sprintf('Nothing to prune - every module has %d versions or fewer.', $keep)
                : sprintf('Nothing to prune - %d older version(s) are held by a snapshot.', array_sum(array_map('count', $held))),
                held: $held);
        }

        $total = array_sum(array_map('count', $plan));

        if ($dryRun) {
            if ($json) {
                $this->line((string) json_encode([
                    'dry_run' => true,
                    'keep' => $keep,
                    'total' => $total,
                    'plan' => $plan,
                    'held' => $held,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->renderPlan($plan);
            $this->renderHeld($held);
            $this->components->info(sprintf('%d version(s) would be removed. Nothing was changed.', $total));

            return self::SUCCESS;
        }

        if (! $json) {
            $this->renderPlan($plan);
            $this->renderHeld($held);
        }

        if (! $this->confirmDestructive(sprintf('Permanently remove %d backup version(s)?', $total))) {
            return $this->nothingToPrune($json, 'Nothing was changed.');
        }

        foreach (array_keys($plan) as $module) {
            try {
                $manager->prune($module, $keep);
            } catch (ModsxException $exception) {
                return $this->reportFailure($json, $exception->getMessage());
            }
        }

        if ($json) {
            $this->line((string) json_encode([
                'dry_run' => false,
                'keep' => $keep,
                'total' => $total,
                'removed' => $plan,
                'held' => $held,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Removed %d backup version(s).', $total));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, list<string>>  $plan
     */
    private function renderPlan(array $plan): void
    {
        foreach ($plan as $module => $versions) {
            $this->components->twoColumnDetail($module, implode(', ', $versions));
        }

        $this->newLine();
    }

    /**
     * @param  array<string, list<string>>  $held
     */
    private function nothingToPrune(bool $json, string $message, bool $warn = false, array $held = []): int
    {
        if ($json) {
            $this->line((string) json_encode(['total' => 0, 'plan' => [], 'held' => $held], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $warn ? $this->components->warn($message) : $this->components->info($message);
        $this->renderHeld($held);

        return self::SUCCESS;
    }

    /**
     * Versions old enough to go that a snapshot is keeping.
     *
     * Printed because otherwise this command silently does less than the age
     * rule says it would, and the difference looks like a bug rather than a
     * safeguard.
     *
     * @param  array<string, list<string>>  $held
     */
    private function renderHeld(array $held): void
    {
        if ($held === []) {
            return;
        }

        foreach ($held as $module => $versions) {
            $this->components->twoColumnDetail(
                sprintf('%s %s', $module, implode(', ', $versions)),
                '<fg=gray>kept, held by a snapshot</>',
            );
        }

        $this->components->bulletList([
            'modsx:snapshotprune lets those snapshots go, after which these versions can be pruned.',
        ]);
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

    /**
     * Remove versions identical to the one that follows them.
     *
     * A separate path rather than another filter on the age rule: the two ask
     * different questions of a version and the answers do not combine. --keep
     * is about how much history to hold on to; this is about history that
     * records nothing, and it applies whatever the count.
     */
    private function handleDuplicates(BackupRepository $backups, BackupManager $manager, bool $json, bool $dryRun): int
    {
        $name = $this->argument('name');

        try {
            $modules = $name === null ? $backups->modules() : [(string) $name];
            $plan = $this->duplicatePlan($backups, $manager, $modules);
        } catch (ModsxException $exception) {
            return $this->reportFailure($json, $exception->getMessage());
        }

        $offered = array_sum(array_map(
            static fn (array $row): int => count($row['remove']) + count($row['commented']),
            $plan
        ));

        if ($offered === 0) {
            return $this->nothingToPrune($json, 'No duplicates - no version is identical to the one after it.');
        }

        if (! $json) {
            $this->renderDuplicates($plan);
        }

        if ($dryRun) {
            if ($json) {
                $this->line((string) json_encode([
                    'dry_run' => true,
                    'duplicates' => true,
                    'total' => $offered,
                    'plan' => $this->planForJson($plan),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $plain = array_sum(array_map(static fn (array $row): int => count($row['remove']), $plan));
            $asked = $offered - $plain;

            // Counting the commented ones as "would be removed" would be a
            // promise this cannot keep: they only go if somebody says so.
            $this->components->info($asked === 0
                ? sprintf('%d version(s) would be removed. Nothing was changed.', $plain)
                : sprintf(
                    '%d version(s) would be removed and %d asked about. Nothing was changed.',
                    $plain,
                    $asked,
                ));

            return self::SUCCESS;
        }

        $plan = $this->settleCommented($plan, $json);

        $total = array_sum(array_map(static fn (array $row): int => count($row['remove']), $plan));

        if ($total === 0) {
            return $this->nothingToPrune($json, 'Nothing was changed.');
        }

        if (! $this->confirmDestructive(sprintf('Permanently remove %d duplicate version(s)?', $total))) {
            return $this->nothingToPrune($json, 'Nothing was changed.');
        }

        $removed = [];

        foreach ($plan as $module => $row) {
            try {
                $gone = $manager->forgetVersions($module, $row['remove']);
            } catch (ModsxException $exception) {
                return $this->reportFailure($json, $exception->getMessage());
            }

            if ($gone !== []) {
                $removed[(string) $module] = $gone;
            }
        }

        if ($json) {
            $this->line((string) json_encode([
                'dry_run' => false,
                'duplicates' => true,
                'total' => array_sum(array_map('count', $removed)),
                'removed' => $removed,
                'kept_commented' => array_filter(array_map(
                    static fn (array $row): array => $row['commented'],
                    $plan
                )),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('%d duplicate version(s) removed.', array_sum(array_map('count', $removed))));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $modules
     * @return array<string, array{remove: list<string>, commented: array<string, string>, kept: list<string>, keeps: array<string, string>}>
     *
     * @throws ModsxException
     */
    private function duplicatePlan(BackupRepository $backups, BackupManager $manager, array $modules): array
    {
        $plan = [];

        foreach ($modules as $module) {
            $remove = [];
            $commented = [];
            $kept = [];
            $keeps = [];

            // Everything a snapshot or the state pointer is holding, asked for
            // once per module rather than once per version.
            $held = $manager->heldFromPrune($module, 1);

            foreach ($manager->duplicateRuns($module) as $run) {
                foreach ($run['remove'] as $version) {
                    $keeps[$version] = $run['keep'];

                    if (in_array($version, $held, true)) {
                        $kept[] = $version;

                        continue;
                    }

                    $comment = $backups->describe($module, $version)['comment'];

                    if ($comment !== null && ! $this->option('with-comments')) {
                        $commented[$version] = $comment;

                        continue;
                    }

                    $remove[] = $version;
                }
            }

            if ($remove !== [] || $commented !== [] || $kept !== []) {
                $plan[(string) $module] = [
                    'remove' => $remove,
                    'commented' => $commented,
                    'kept' => $kept,
                    'keeps' => $keeps,
                ];
            }
        }

        return $plan;
    }

    /**
     * @param  array<string, array{remove: list<string>, commented: array<string, string>, kept: list<string>, keeps: array<string, string>}>  $plan
     */
    private function renderDuplicates(array $plan): void
    {
        foreach ($plan as $module => $row) {
            $this->components->info((string) $module);

            foreach ($row['remove'] as $version) {
                $this->components->twoColumnDetail(
                    $version,
                    sprintf('<fg=gray>identical to %s</>', $row['keeps'][$version] ?? '?'),
                );
            }

            foreach ($row['commented'] as $version => $comment) {
                $this->components->twoColumnDetail(
                    sprintf('%s  <fg=yellow>%s</>', $version, $comment),
                    sprintf('<fg=gray>identical to %s, will ask</>', $row['keeps'][$version] ?? '?'),
                );
            }

            foreach ($row['kept'] as $version) {
                $this->components->twoColumnDetail($version, '<fg=gray>kept, held by a snapshot</>');
            }
        }

        $this->newLine();
    }

    /**
     * Ask about each commented version, showing what the comment says.
     *
     * A comment is somebody's note about a moment, and the content being
     * duplicated says nothing about whether the note is worth keeping. Only
     * the person who wrote it can answer that, so it is put to them with the
     * words in front of them.
     *
     * @param  array<string, array{remove: list<string>, commented: array<string, string>, kept: list<string>, keeps: array<string, string>}>  $plan
     * @return array<string, array{remove: list<string>, commented: array<string, string>, kept: list<string>, keeps: array<string, string>}>
     */
    private function settleCommented(array $plan, bool $json): array
    {
        foreach ($plan as $module => $row) {
            if ($row['commented'] === []) {
                continue;
            }

            // Nobody to ask. Keeping is the right answer to an unasked
            // question about somebody's note; --with-comments is how a script
            // says it has already decided.
            if ($json || ! $this->input->isInteractive()) {
                if (! $json) {
                    $this->components->warn(sprintf(
                        '[%s] has %d duplicate version(s) carrying a comment, and they were kept. Pass --with-comments to remove them.',
                        $module,
                        count($row['commented']),
                    ));
                }

                continue;
            }

            $options = [];

            foreach ($row['commented'] as $version => $comment) {
                $options[$version] = $version.'  '.$comment;
            }

            $chosen = multiselect(
                label: sprintf('[%s]: which of these commented duplicates should go?', $module),
                options: $options,
                hint: 'None are selected to start with. The content survives in the version each is identical to.',
            );

            $plan[$module]['remove'] = array_values(array_merge($row['remove'], array_map('strval', $chosen)));
        }

        return $plan;
    }

    /**
     * @param  array<string, array{remove: list<string>, commented: array<string, string>, kept: list<string>, keeps: array<string, string>}>  $plan
     * @return array<string, array{remove: list<string>, commented: array<string, string>, kept: list<string>}>
     */
    private function planForJson(array $plan): array
    {
        return array_map(static fn (array $row): array => [
            'remove' => $row['remove'],
            'commented' => $row['commented'],
            'kept' => $row['kept'],
        ], $plan);
    }
}
