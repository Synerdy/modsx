<?php

declare(strict_types=1);

namespace Modsx;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;
use Modsx\Exceptions\ModsxException;
use Symfony\Component\Finder\Finder;

/**
 * Finds module directories inside the application.
 *
 * All paths returned by this class are relative to the project root and use
 * forward slashes, on every platform.
 */
class ModuleLocator
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    /**
     * The kebab-case prefix, e.g. "modsx".
     */
    public function prefix(): string
    {
        $configured = trim((string) $this->config->get('modsx.prefix', 'modsx'));

        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $configured) !== 1) {
            throw ModsxException::invalidPrefix($configured);
        }

        return Str::kebab(Str::studly($configured));
    }

    /**
     * The StudlyCase prefix, e.g. "Modsx".
     */
    public function prefixStudly(): string
    {
        return Str::studly($this->prefix());
    }

    /**
     * Every module present in the application.
     *
     * @return array<string, list<string>> canonical name => relative paths
     */
    public function all(): array
    {
        return $this->discover(null);
    }

    /**
     * Names of every module present in the application.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * Directories belonging to one module.
     *
     * @return list<string>
     */
    public function paths(ModuleName|string $name): array
    {
        $name = ModuleName::make($name);

        return $this->discover($name)[$name->studly] ?? [];
    }

    public function exists(ModuleName|string $name): bool
    {
        return $this->paths($name) !== [];
    }

    /**
     * Modules whose names differ only in word boundaries, e.g. "UserProfile"
     * and "Userprofile". Almost always a typo in a directory name, and one
     * nothing else will report: both are valid module names on their own.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function ambiguousNames(): array
    {
        $groups = [];

        foreach ($this->all() as $studly => $paths) {
            $groups[strtolower($studly)][$studly] = $paths;
        }

        return array_filter($groups, static fn (array $group): bool => count($group) > 1);
    }

    /**
     * The module a directory name belongs to, or null if it belongs to none.
     */
    public function moduleNameFromDirectory(string $directory): ?ModuleName
    {
        $kebabPrefix = $this->prefix().'-';

        if (str_starts_with($directory, $kebabPrefix)) {
            return ModuleName::tryMake(substr($directory, strlen($kebabPrefix)));
        }

        $studlyPrefix = $this->prefixStudly();

        if (str_starts_with($directory, $studlyPrefix) && strlen($directory) > strlen($studlyPrefix)) {
            return ModuleName::tryMake(substr($directory, strlen($studlyPrefix)));
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function discover(?ModuleName $only): array
    {
        $roots = $this->roots();

        if ($roots === []) {
            return [];
        }

        $finder = (new Finder)
            ->directories()
            ->in($roots)
            ->exclude($this->excluded())
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
            // Finder does not follow symlinks unless followLinks() is called - do
            // not call it. It takes no arguments, so followLinks(false) would
            // silently discard that argument and switch symlink-following on,
            // letting discovery walk outside the project root through a link.
            ->name([
                $this->prefix().'-'.($only?->kebab ?? '*'),
                $this->prefixStudly().($only?->studly ?? '*'),
            ])
            ->sortByName();

        $backupRoot = $this->relativeToBase($this->backupPath());

        $found = [];

        foreach ($finder as $directory) {
            $relative = $this->relativeToBase($directory->getPathname());

            if ($relative === null || $relative === '') {
                continue;
            }

            // Never treat the backup tree as application code, wherever it lives.
            if ($backupRoot !== null && str_starts_with($relative.'/', $backupRoot.'/')) {
                continue;
            }

            $module = $this->moduleNameFromDirectory($directory->getFilename());

            if ($module === null || ($only !== null && ! $module->equals($only))) {
                continue;
            }

            $found[$module->studly][] = $relative;
        }

        foreach ($found as $studly => $paths) {
            $found[$studly] = $this->withoutNested(array_values(array_unique($paths)));
        }

        ksort($found);

        return $found;
    }

    /**
     * @return list<string> absolute paths of existing scan roots
     */
    private function roots(): array
    {
        $roots = [];

        foreach ((array) $this->config->get('modsx.scan_paths', []) as $relative) {
            $path = base_path(trim((string) $relative, '/\\'));

            if (is_dir($path)) {
                $roots[] = $path;
            }
        }

        return $roots;
    }

    /**
     * @return list<string>
     */
    private function excluded(): array
    {
        return array_values(array_map(
            static fn ($value): string => trim((string) $value, '/\\'),
            (array) $this->config->get('modsx.exclude', [])
        ));
    }

    private function backupPath(): string
    {
        return (string) $this->config->get('modsx.backup_path', base_path('ModulesX'));
    }

    /**
     * Path relative to the project root, or null if it lies outside it.
     */
    private function relativeToBase(string $absolute): ?string
    {
        $base = $this->normalise(base_path());
        $path = $this->normalise($absolute);

        if ($path === $base) {
            return '';
        }

        if (! str_starts_with($path, $base.'/')) {
            return null;
        }

        return substr($path, strlen($base) + 1);
    }

    private function normalise(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Drops paths contained in another matched path, so a module nested inside
     * itself is copied once rather than twice.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function withoutNested(array $paths): array
    {
        sort($paths);

        $keep = [];

        foreach ($paths as $path) {
            foreach ($keep as $kept) {
                if (str_starts_with($path.'/', $kept.'/')) {
                    continue 2;
                }
            }

            $keep[] = $path;
        }

        return $keep;
    }
}
