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

        ██▄  ▄██ ▄████▄ ████▄  ▄█████ ██  ██
        ██ ▀▀ ██ ██  ██ ██  ██ ▀▀▀▄▄▄  ████
        ██    ██ ▀████▀ ████▀  █████▀ ██  ██  v
        LOGO.ModsxServiceProvider::version()."\n";
    }

    protected function banner(): void
    {
        $this->line('<fg=yellow>'.$this->logo().'</>');
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
     * Resolve which backup version to act on: the given value if there is
     * one, otherwise an interactive pick defaulting to the newest, otherwise
     * the newest outright when running non-interactively or when $forceDefault
     * is set (used for --json, where prompting would break scripted output
     * even in an interactive terminal).
     *
     * @param  list<string>  $versions  non-empty, ordered oldest to newest
     */
    protected function pickVersion(?string $given, array $versions, string $name, bool $forceDefault = false): string
    {
        if ($given !== null) {
            return $given;
        }

        $newest = $versions[count($versions) - 1];

        if ($forceDefault || ! $this->input->isInteractive()) {
            return $newest;
        }

        return select(
            label: sprintf('Which version of [%s]?', $name),
            options: array_combine($versions, $versions),
            default: $newest,
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
