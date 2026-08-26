<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Modsx\BackupManager;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;

/**
 * Unpack a .zip produced by modsx:export back into the backup tree, at the
 * module and version its own manifest names.
 */
class ImportCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:import
                            {path : Path to a .zip file created by modsx:export}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Unpack a .zip exported by modsx:export back into the backup tree';

    public function handle(BackupManager $manager): int
    {
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->banner();
        }

        try {
            $result = $manager->import((string) $this->argument('path'));
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

        $this->components->info(sprintf('Imported [%s] as version %s.', $result['module'], $result['version']));
        $this->components->twoColumnDetail(
            'Restore with',
            sprintf('php artisan modsx:restore %s %s', $result['module'], $result['version'])
        );

        return self::SUCCESS;
    }
}
