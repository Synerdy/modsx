<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\BackupManager;
use Modsx\BackupRepository;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;

class PruneCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modules:prune
                            {name? : Module name; omit for every module with backups}
                            {--keep= : How many of the newest versions to keep}
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

        $keep = max(1, (int) ($this->option('keep') ?? config('modsx.prune.keep', 5)));
        $dryRun = (bool) $this->option('dry-run');

        $name = $this->argument('name');
        $modules = $name === null ? $backups->modules() : [(string) $name];

        if ($modules === []) {
            return $this->nothingToPrune($json, 'No backups found in '.$backups->root().'.', warn: true);
        }

        $plan = [];

        foreach ($modules as $module) {
            try {
                $plan[(string) $module] = $manager->prune($module, $keep, dryRun: true);
            } catch (ModsxException $exception) {
                return $this->reportFailure($json, $exception->getMessage());
            }
        }

        $plan = array_filter($plan, static fn (array $versions): bool => $versions !== []);

        if ($plan === []) {
            return $this->nothingToPrune($json, sprintf('Nothing to prune - every module has %d versions or fewer.', $keep));
        }

        $total = array_sum(array_map('count', $plan));

        if ($dryRun) {
            if ($json) {
                $this->line((string) json_encode([
                    'dry_run' => true,
                    'keep' => $keep,
                    'total' => $total,
                    'plan' => $plan,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->renderPlan($plan);
            $this->components->info(sprintf('%d version(s) would be removed. Nothing was changed.', $total));

            return self::SUCCESS;
        }

        if (! $json) {
            $this->renderPlan($plan);
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

    private function nothingToPrune(bool $json, string $message, bool $warn = false): int
    {
        if ($json) {
            $this->line((string) json_encode(['total' => 0, 'plan' => []], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $warn ? $this->components->warn($message) : $this->components->info($message);

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
