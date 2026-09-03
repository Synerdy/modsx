<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\Console\Concerns\ConfirmsDestructiveActions;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;
use Modsx\ModuleState;
use Modsx\SnapshotManager;
use Modsx\SnapshotRepository;

/**
 * Put the whole project back to a snapshot.
 *
 * A separate verb from modsx:restore on purpose. Restoring is one module and
 * one version; this moves everything at once, and the two are not the sort of
 * thing anyone should be able to confuse at two in the morning.
 *
 * The plan is shown before the prompt, because the number that matters is not
 * how many modules will move but which of them are already somewhere else.
 */
class RollbackCommand extends Command
{
    use ConfirmsDestructiveActions;
    use InteractsWithModules;

    protected $signature = 'modsx:rollback
                            {snapshot? : Snapshot to roll back to; omit for the newest}
                            {--force : Skip the confirmation prompt}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Roll the project back to a snapshot';

    public function handle(
        SnapshotRepository $snapshots,
        SnapshotManager $manager,
        ModuleState $state,
    ): int {
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->banner();
        }

        $id = $this->argument('snapshot') ?? $snapshots->latest();

        if ($id === null) {
            return $this->refuse(ModsxException::noSnapshots()->getMessage(), $json);
        }

        try {
            $snapshot = $snapshots->read((string) $id);
        } catch (ModsxException $exception) {
            return $this->refuse($exception->getMessage(), $json);
        }

        if ($snapshot === null) {
            return $this->refuse(ModsxException::snapshotNotFound((string) $id)->getMessage(), $json);
        }

        if (! $json) {
            $this->plan($snapshot, $state);
        }

        // Gated even in --json. Machine-readable output is not permission: a
        // script rolling the whole project back still has to say --force,
        // which is the only way to answer a prompt it cannot see.
        if (! $this->confirmDestructive(sprintf(
            'Roll %d module(s) back to snapshot %s?',
            $this->moving($snapshot, $state),
            $snapshot['snapshot'],
        ))) {
            return $this->refuse('Nothing was changed.', $json);
        }

        try {
            $result = $manager->rollback((string) $id);
        } catch (ModsxException $exception) {
            return $this->refuse($exception->getMessage(), $json);
        }

        if ($json) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Rolled back to snapshot %s. %d module(s) restored.',
            $result['snapshot'],
            count($result['restored']),
        ));

        $this->components->twoColumnDetail(
            'The state before this is snapshot',
            sprintf('<fg=gray>%s</>', $result['safety']),
        );

        return self::SUCCESS;
    }

    /**
     * Show what will move before asking about it.
     *
     * The number that matters is not how many modules the snapshot holds but
     * how many of them are somewhere else right now.
     *
     * @param  array{snapshot: string, created_at: ?string, comment: ?string, root: ?string, modules: array<string, string>, dependencies: array<string, list<string>>}  $snapshot
     */
    private function plan(array $snapshot, ModuleState $state): void
    {
        $rows = [];

        foreach ($snapshot['modules'] as $module => $version) {
            $current = $state->current($module);

            $rows[] = [
                $module,
                $current ?? '-',
                $version,
                $current === $version ? '<fg=gray>already there</>' : '<fg=yellow>will move</>',
            ];
        }

        $this->table(['Module', 'Current', 'Snapshot', ''], $rows);

        $this->components->warn(
            'Anything not in the snapshot is replaced by what was. Files added since are moved aside and not put back.'
        );
    }

    /**
     * @param  array{snapshot: string, created_at: ?string, comment: ?string, root: ?string, modules: array<string, string>, dependencies: array<string, list<string>>}  $snapshot
     */
    private function moving(array $snapshot, ModuleState $state): int
    {
        $moving = 0;

        foreach ($snapshot['modules'] as $module => $version) {
            if ($state->current($module) !== $version) {
                $moving++;
            }
        }

        return $moving;
    }

    private function refuse(string $message, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode(['error' => $message], JSON_PRETTY_PRINT));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
