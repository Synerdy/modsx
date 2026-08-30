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
     * A file's name identifies a module exactly as a directory's name does, so
     * modsx-blog-admin.php names BlogAdmin rather than being another of Blog's
     * files. That is what makes two modules unable to claim one file: names
     * are unique, so at most one module can match.
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

            if ($this->moduleNameFromFilename($file->getFilename())?->equals($name) !== true) {
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
     * A migration is the one thing that cannot be named for its module and
     * nothing else, since every migration needs its own name. Which module a
     * suffixed name belongs to is settled by migrationOwner().
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

        // Not recursive, matching Laravel's own migrator, which globs
        // "$path/*_*.php" and does not descend.
        $finder = (new Finder)
            ->files()
            ->in($directory)
            ->depth(0)
            ->name('*.php')
            ->ignoreDotFiles(true)
            ->sortByName();

        $owners = $this->migrationOwners();
        $found = [];

        foreach ($finder as $file) {
            $owner = self::migrationOwner(self::withoutTimestamp($file->getFilename()), $owners);

            if ($owner?->equals($name) !== true) {
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
     * Every module in the application, keyed by the snake prefix a migration of
     * theirs starts with, longest first.
     *
     * @return array<string, ModuleName>
     */
    private function migrationOwners(): array
    {
        $prefix = $this->prefix();
        $owners = [];

        foreach ($this->names() as $module) {
            $name = ModuleName::make($module);
            $owners[$prefix.'_'.$name->snake] = $name;
        }

        uksort($owners, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $owners;
    }

    /**
     * The module a migration belongs to, given every module in the application.
     *
     * Both Blog and BlogPost match "modsx_blog_post_create_comments_table" at a
     * word boundary, so the longer name wins: it is the one that cannot be a
     * coincidence. Blog's own migration about posts is named
     * "modsx_blog_create_posts_table" by the same convention and matches Blog
     * alone, because "create" is not the start of any module name.
     *
     * This needs no vocabulary of migration verbs. It is the module list that
     * draws the boundary - which is just as well, since "make:migration" takes
     * any name at all ("backfill_", "cleanup_") and module names are themselves
     * sometimes verbs ("Import", "Update").
     *
     * @param  string  $rest  the filename with its timestamp already removed
     * @param  array<string, ModuleName>  $owners  from migrationOwners(), longest first
     */
    private static function migrationOwner(string $rest, array $owners): ?ModuleName
    {
        foreach ($owners as $needle => $owner) {
            if (self::startsWithAtBoundary($rest, $needle, ['_', '.'])) {
                return $owner;
            }
        }

        return null;
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
     * Single files naming a module that does not exist.
     *
     * config/modsx-blog-admin.php names BlogAdmin the same way a directory of
     * that name would. With no such module it belongs to nothing and is backed
     * up with nothing - which is worth saying out loud, because the file itself
     * keeps working and nothing else would ever mention it.
     *
     * @return list<array{module: string, path: string}>
     */
    public function unclaimedFiles(): array
    {
        $roots = $this->roots();

        if ($roots === []) {
            return [];
        }

        $modules = $this->all();
        $directories = array_merge(...array_values($modules)) ?: [];
        $backupRoot = $this->relativeToBase($this->backupPath());

        $finder = (new Finder)
            ->files()
            ->in($roots)
            ->exclude($this->excluded())
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
            ->sortByName();

        $found = [];

        foreach ($finder as $file) {
            $module = $this->moduleNameFromFilename($file->getFilename());

            if ($module === null || isset($modules[$module->studly])) {
                continue;
            }

            $relative = $this->relativeToBase($file->getPathname());

            if ($relative === null || $relative === '') {
                continue;
            }

            if ($backupRoot !== null && str_starts_with($relative.'/', $backupRoot.'/')) {
                continue;
            }

            // Inside a module's own directory the file travels with that
            // directory, so its name naming nothing is of no consequence.
            foreach ($directories as $directory) {
                if (str_starts_with($relative, $directory.'/')) {
                    continue 2;
                }
            }

            $found[] = ['module' => $module->studly, 'path' => $relative];
        }

        return $found;
    }

    /**
     * The module a single file's name belongs to, or null if it belongs to none.
     *
     * The name is read exactly as a directory name is, taking everything before
     * the first dot: "modsx-blog.blade.php" is Blog's, and "modsx-blog-admin.php"
     * names BlogAdmin rather than being one more of Blog's files. Cutting at the
     * first dot rather than the last is what keeps ".blade.php" working.
     *
     * Only the kebab form counts here. A lone "ModsxBlog.php" is nobody's, the
     * Studly form being reserved for directories - the namespace directories a
     * class actually lives in.
     */
    public function moduleNameFromFilename(string $filename): ?ModuleName
    {
        $stem = strstr($filename, '.', true);

        if ($stem === false) {
            $stem = $filename;
        }

        $kebabPrefix = $this->prefix().'-';

        if (! str_starts_with($stem, $kebabPrefix)) {
            return null;
        }

        return ModuleName::tryMake(substr($stem, strlen($kebabPrefix)));
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
