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
     * Single files belonging to a module: routes/modsx-blog.php,
     * config/modsx-blog.php, lang/en/modsx-blog.php and the like.
     *
     * The prefix belongs to the module exclusively, so modsx-blog-admin.php is
     * Blog's too - but modsx-blogging.php is not, because the match has to end
     * on a word boundary.
     *
     * @return list<string>
     */
    public function files(ModuleName|string $name): array
    {
        $name = ModuleName::make($name);
        $roots = $this->roots();

        if ($roots === []) {
            return [];
        }

        $finder = (new Finder)
            ->files()
            ->in($roots)
            ->exclude($this->excluded())
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
            ->sortByName();

        $prefix = $this->prefix().'-'.$name->kebab;
        $backupRoot = $this->relativeToBase($this->backupPath());
        $directories = $this->paths($name);

        $found = [];

        foreach ($finder as $file) {
            $relative = $this->relativeToBase($file->getPathname());

            if ($relative === null || $relative === '') {
                continue;
            }

            if ($backupRoot !== null && str_starts_with($relative.'/', $backupRoot.'/')) {
                continue;
            }

            if (! self::startsWithAtBoundary($file->getFilename(), $prefix, ['-', '.'])) {
                continue;
            }

            // A file inside one of the module's own directories is already
            // covered by copying that directory whole; listing it again would
            // back it up twice.
            foreach ($directories as $directory) {
                if (str_starts_with($relative, $directory.'/')) {
                    continue 2;
                }
            }

            $found[] = $relative;
        }

        sort($found);

        return $found;
    }

    /**
     * Migrations belonging to a module.
     *
     * These are archived, never restored and never deleted: this package does
     * not touch the database, so putting an old migration file back while the
     * schema stays current would leave the repository disagreeing with the
     * database. Keeping a copy to look at costs nothing and breaks nothing.
     *
     * The convention is that the name after the timestamp starts with the
     * module's snake form, so 2026_01_01_000000_modsx_blog_posts_table.php
     * belongs to Blog. Anchoring at a known position is what keeps this
     * unambiguous - searching the middle of the name would not be.
     *
     * @return list<string>
     */
    public function migrations(ModuleName|string $name): array
    {
        $name = ModuleName::make($name);
        $directory = base_path('database/migrations');

        if (! is_dir($directory)) {
            return [];
        }

        $prefix = $this->prefix().'_'.$name->snake;

        // Not recursive, matching Laravel's own migrator, which globs
        // "$path/*_*.php" and does not descend.
        $finder = (new Finder)
            ->files()
            ->in($directory)
            ->depth(0)
            ->name('*.php')
            ->ignoreDotFiles(true)
            ->sortByName();

        $found = [];

        foreach ($finder as $file) {
            if (! self::startsWithAtBoundary(self::withoutTimestamp($file->getFilename()), $prefix, ['_', '.'])) {
                continue;
            }

            $relative = $this->relativeToBase($file->getPathname());

            if ($relative !== null && $relative !== '') {
                $found[] = $relative;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * The same migration renamed to follow the convention: timestamp, then the
     * module prefix, then whatever the name said before.
     *
     * "2026_01_01_000000_create_modsx_blog_posts_table.php"
     *   -> "2026_01_01_000000_modsx_blog_create_posts_table.php"
     */
    private static function suggestMigrationName(string $basename, string $rest, string $needle): string
    {
        $timestamp = substr($basename, 0, strlen($basename) - strlen($rest));

        // Lifting the prefix out of the middle leaves the underscores that
        // used to sit on either side of it: "create_modsx_blog_posts" becomes
        // "create__posts" until they are collapsed.
        $remainder = trim((string) preg_replace('/_{2,}/', '_', str_replace($needle, '', $rest)), '_');

        return $timestamp.$needle.'_'.$remainder;
    }

    /**
     * A migration filename with Laravel's leading timestamp removed, or
     * unchanged when it carries none.
     */
    public static function withoutTimestamp(string $filename): string
    {
        return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $filename);
    }

    /**
     * True when $subject starts with $prefix and the next character is one of
     * $boundaries, or there is none.
     *
     * The boundary is what separates "modsx_blog_posts" (Blog's) from
     * "modsx_blogging_posts" (not Blog's).
     *
     * @param  list<string>  $boundaries
     */
    private static function startsWithAtBoundary(string $subject, string $prefix, array $boundaries): bool
    {
        if (! str_starts_with($subject, $prefix)) {
            return false;
        }

        $rest = substr($subject, strlen($prefix));

        return $rest === '' || in_array($rest[0], $boundaries, true);
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
     * Pairs of modules where one name owns a prefix the other sits inside,
     * such as "Blog" next to "BlogPost".
     *
     * Both are valid names, but "modsx_blog_post_*" then reads as belonging to
     * either, and discovery has no way to tell which was meant. Nothing here
     * guesses: the pair is reported so one of them can be renamed.
     *
     * @return list<array{owner: string, nested: string}>
     */
    public function prefixCollisions(): array
    {
        $names = $this->names();
        $collisions = [];

        foreach ($names as $owner) {
            $ownerSnake = ModuleName::make($owner)->snake;

            foreach ($names as $nested) {
                if ($owner === $nested) {
                    continue;
                }

                if (str_starts_with(ModuleName::make($nested)->snake, $ownerSnake.'_')) {
                    $collisions[] = ['owner' => $owner, 'nested' => $nested];
                }
            }
        }

        return $collisions;
    }

    /**
     * Migrations that name a module but not the way the convention requires,
     * so they are silently not archived with it.
     *
     * The classic "create_modsx_blog_posts_table" lands here: it mentions the
     * module, but the name after the timestamp does not start with it. Without
     * this the convention just quietly does nothing and nobody finds out why.
     *
     * @return list<array{module: string, path: string, suggestion: string}>
     */
    public function misnamedMigrations(): array
    {
        $directory = base_path('database/migrations');

        if (! is_dir($directory)) {
            return [];
        }

        $prefix = $this->prefix();
        $names = $this->names();

        $finder = (new Finder)
            ->files()
            ->in($directory)
            ->depth(0)
            ->name('*.php')
            ->ignoreDotFiles(true)
            ->sortByName();

        $found = [];

        foreach ($finder as $file) {
            $basename = $file->getFilename();
            $rest = self::withoutTimestamp($basename);

            foreach ($names as $module) {
                $needle = $prefix.'_'.ModuleName::make($module)->snake;

                if (self::startsWithAtBoundary($rest, $needle, ['_', '.'])) {
                    continue 2;
                }

                if (! str_contains($rest, $needle)) {
                    continue;
                }

                $relative = $this->relativeToBase($file->getPathname());

                if ($relative === null || $relative === '') {
                    continue;
                }

                $found[] = [
                    'module' => $module,
                    'path' => $relative,
                    'suggestion' => self::suggestMigrationName($basename, $rest, $needle),
                ];

                continue 2;
            }
        }

        return $found;
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
        return (string) $this->config->get('modsx.backup_path', base_path('modsx-backups'));
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
