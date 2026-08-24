<?php

declare(strict_types=1);

namespace Modsx\Console\Concerns;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use Modsx\BackupRepository;
use Modsx\ModsxServiceProvider;
use Modsx\ModuleLocator;

trait InteractsWithModules
{
    protected function logo(): string
    {
        return <<<'LOGO'

         __  __  ___  ___  ___ __  __
        |  \/  |/ _ \|   \/ __|\ \/ /
        | |\/| | (_) | |) \__ \ >  <
        |_|  |_|\___/|___/|___//_/\_\  v
        LOGO.ModsxServiceProvider::VERSION."\n";
    }

    protected function banner(): void
    {
        $this->line('<fg=gray>'.$this->logo().'</>');
    }

    /**
     * Ask which module to act on, listing the ones present in the application.
     */
    protected function pickModule(ModuleLocator $locator, string $label): ?string
    {
        $names = $locator->names();

        if ($names === []) {
            $this->components->warn('No modules found in the application.');

            return null;
        }

        return select(
            label: $label,
            options: array_combine($names, $names),
            scroll: 10,
        );
    }

    /**
     * Ask which module to act on, listing the ones that have backups.
     */
    protected function pickBackedUpModule(BackupRepository $backups, string $label): ?string
    {
        $names = $backups->modules();

        if ($names === []) {
            $this->components->warn('No backups found in '.$backups->root().'.');

            return null;
        }

        return select(
            label: $label,
            options: array_combine($names, $names),
            scroll: 10,
        );
    }

    /**
     * Ask which module to act on, listing everything known: modules present in
     * the application and modules that exist only as backups.
     */
    protected function pickKnownModule(ModuleLocator $locator, BackupRepository $backups, string $label): ?string
    {
        $names = array_values(array_unique(array_merge($locator->names(), $backups->modules())));

        sort($names);

        if ($names === []) {
            $this->components->warn('No modules found, in the application or in the backups.');

            return null;
        }

        return select(
            label: $label,
            options: array_combine($names, $names),
            scroll: 10,
        );
    }

    /**
     * Confirm a destructive action, unless --force was passed.
     */
    protected function confirmDestructive(string $question): bool
    {
        if ($this->getDefinition()->hasOption('force') && $this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('This command changes files. Re-run it with --force in non-interactive mode.');

            return false;
        }

        return confirm(label: $question, default: false);
    }

    /**
     * @param  list<string>  $paths
     */
    protected function listPaths(array $paths, string $verb): void
    {
        foreach ($paths as $path) {
            $this->components->twoColumnDetail($path, '<fg=gray>'.$verb.'</>');
        }
    }
}
