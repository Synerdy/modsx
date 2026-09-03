<?php

declare(strict_types=1);

namespace Modsx;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\File;

/**
 * Which modules a module needs, worked out by reading it.
 *
 * The graph is derived, not declared. A declared list of requirements is a
 * register - the one thing this package does not keep - and worse, it is a
 * register nothing checks: add a reference to another module and forget to
 * write it down, and a snapshot built from that list is quietly incomplete.
 * An incomplete snapshot is worse than none, because it is trusted.
 *
 * It can be derived because the convention is the naming. A module called
 * Media appears in other modules' code as "ModsxMedia", "modsx-media" or
 * "modsx_media" and in no other form, so a reference to it is something that
 * can be found rather than something that has to be remembered.
 *
 * A mention inside a comment or a string counts as a dependency here. That is
 * deliberate: the error it causes is a snapshot holding one module too many,
 * which costs a version directory, while the opposite error breaks a restore.
 *
 * Configuration adds edges, and never replaces them - for what reading cannot
 * see, such as a class name assembled from a string.
 */
class ModuleDependencies
{
    /**
     * Files larger than this are not read.
     *
     * A module's own source is never this big; what is, is a bundled asset or
     * a fixture, and reading it would cost more than the edge it might find.
     */
    private const MAX_BYTES = 2_000_000;

    public function __construct(
        private readonly ModuleLocator $locator,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Every module's direct dependencies.
     *
     * @return array<string, list<array{module: string, via: string}>>
     */
    public function graph(): array
    {
        $graph = [];

        foreach ($this->locator->names() as $name) {
            $graph[$name] = $this->for($name);
        }

        return $graph;
    }

    /**
     * What one module needs directly, with where each edge came from.
     *
     * @return list<array{module: string, via: string}>
     */
    public function for(ModuleName|string $name): array
    {
        $name = ModuleName::make($name);

        $others = [];

        foreach ($this->locator->names() as $other) {
            if ($other !== $name->studly) {
                $others[$other] = ModuleName::make($other);
            }
        }

        $found = [];

        foreach ($this->contents($name) as $text) {
            foreach ($others as $studly => $module) {
                if (! isset($found[$studly]) && $this->mentions($text, $module)) {
                    $found[$studly] = 'scan';
                }
            }

            // Nothing left to look for; the rest of the module cannot add an
            // edge that is already there.
            if (count($found) === count($others)) {
                break;
            }
        }

        foreach ($this->configured($name) as $declared) {
            if (isset($others[$declared])) {
                // An edge the scan also found is reported as found: it is the
                // stronger claim of the two, because it can be pointed at.
                $found[$declared] ??= 'config';
            }
        }

        ksort($found);

        $edges = [];

        foreach ($found as $module => $via) {
            $edges[] = ['module' => $module, 'via' => $via];
        }

        return $edges;
    }

    /**
     * A module and everything it needs, directly or otherwise.
     *
     * The module itself comes first. A cycle cannot loop this: a module
     * already in the answer is not walked a second time.
     *
     * @return list<string>
     */
    public function closure(ModuleName|string $name): array
    {
        $graph = $this->graph();
        $start = ModuleName::make($name)->studly;

        $seen = [$start => true];
        $queue = [$start];
        $order = [$start];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($graph[$current] ?? [] as $edge) {
                if (! isset($seen[$edge['module']])) {
                    $seen[$edge['module']] = true;
                    $queue[] = $edge['module'];
                    $order[] = $edge['module'];
                }
            }
        }

        return $order;
    }

    /**
     * Groups of modules that depend on one another, directly or in a ring.
     *
     * Not a fault. It means those modules cannot be moved or restored apart
     * from each other, which is worth saying out loud but is a design someone
     * may well have chosen.
     *
     * @return list<list<string>>
     */
    public function cycles(): array
    {
        $graph = $this->graph();
        $cycles = [];
        $stack = [];
        $onStack = [];
        $visited = [];

        $walk = function (string $module) use (&$walk, $graph, &$cycles, &$stack, &$onStack, &$visited): void {
            $visited[$module] = true;
            $stack[] = $module;
            $onStack[$module] = true;

            foreach ($graph[$module] ?? [] as $edge) {
                $next = $edge['module'];

                if (isset($onStack[$next])) {
                    $cycles[] = array_slice($stack, (int) array_search($next, $stack, true));
                } elseif (! isset($visited[$next])) {
                    $walk($next);
                }
            }

            array_pop($stack);
            unset($onStack[$module]);
        };

        foreach (array_keys($graph) as $module) {
            if (! isset($visited[$module])) {
                $walk($module);
            }
        }

        return $this->unique($cycles);
    }

    /**
     * One cycle reached from two starting points is one cycle, listed once.
     *
     * @param  list<list<string>>  $cycles
     * @return list<list<string>>
     */
    private function unique(array $cycles): array
    {
        $seen = [];
        $unique = [];

        foreach ($cycles as $cycle) {
            $sorted = $cycle;
            sort($sorted);
            $key = implode('|', $sorted);

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $cycle;
            }
        }

        return $unique;
    }

    /**
     * Extra edges from configuration, for what reading the files cannot see.
     *
     * @return list<string>
     */
    private function configured(ModuleName $name): array
    {
        /** @var array<string, mixed> $declared */
        $declared = $this->config->get('modsx.dependencies', []);

        $entry = $declared[$name->studly] ?? [];

        if (! is_array($entry)) {
            return [];
        }

        return array_values(array_filter($entry, 'is_string'));
    }

    /**
     * Does this text name that module, in any of the three forms it takes?
     *
     * The boundary matters as much as the name. "ModsxBlog" is the start of
     * "ModsxBlogPost", so without it every reference to BlogPost would also
     * register as a reference to Blog - the same trap that made a file appear
     * to belong to two modules before 0.5.0.
     *
     * The three forms do not get the same boundary. A namespace and a view
     * path name a directory, which is exact, so a following letter or digit
     * means a different module. The snake form names a table, where a suffix
     * is ordinary - "modsx_media_assets" is Media's table - so an underscore
     * is allowed to follow. Where a module called MediaAssets also exists,
     * that string marks a dependency on both; over-inclusion costs a version
     * directory in a snapshot, while missing an edge breaks a rollback.
     */
    private function mentions(string $text, ModuleName $other): bool
    {
        $prefix = $this->locator->prefix();

        $forms = [
            $this->locator->prefixStudly().$other->studly => '[A-Za-z0-9]',
            $prefix.'-'.$other->kebab => '[A-Za-z0-9-]',
            $prefix.'_'.$other->snake => '[A-Za-z0-9]',
        ];

        foreach ($forms as $needle => $boundary) {
            if (str_contains($text, $needle)
                && preg_match('/'.preg_quote($needle, '/').'(?!'.$boundary.')/', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The readable text of everything the module owns.
     *
     * @return iterable<string>
     */
    private function contents(ModuleName $name): iterable
    {
        foreach ($this->locator->paths($name) as $relative) {
            $directory = base_path($relative);

            if (! File::isDirectory($directory)) {
                continue;
            }

            foreach (File::allFiles($directory, true) as $file) {
                $text = $this->read($file->getPathname());

                if ($text !== null) {
                    yield $text;
                }
            }
        }

        foreach ($this->locator->files($name) as $relative) {
            $text = $this->read(base_path($relative));

            if ($text !== null) {
                yield $text;
            }
        }
    }

    private function read(string $path): ?string
    {
        if (! File::isFile($path) || File::size($path) > self::MAX_BYTES) {
            return null;
        }

        $text = File::get($path);

        // A null byte means this is not text, and a compiled asset that happens
        // to contain a module's name is not a reference to it.
        return str_contains($text, "\0") ? null : $text;
    }
}
