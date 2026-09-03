<?php

declare(strict_types=1);

namespace Modsx;

use Illuminate\Support\Facades\File;
use Modsx\Exceptions\ModsxException;

/**
 * The snapshot store: a tag over the version store, nothing more.
 *
 * A snapshot records which version of each module was current at one moment.
 * It copies nothing - the versions it names already exist in the backup tree,
 * and copying them would double the disk cost and give a version two places to
 * live. What is stored is a few hundred bytes of version numbers.
 *
 * Knowing nothing about BackupManager is deliberate: prune has to ask which
 * versions a snapshot is holding on to, and it asks here. Were that question
 * answered anywhere further up, the two would depend on each other.
 */
class SnapshotRepository
{
    /**
     * Underscored for the same reason as the migration archive: a module name
     * has to start with a letter, so nothing here can ever be read as one.
     */
    public const DIRECTORY = '_snapshots';

    public function __construct(
        private readonly BackupRepository $backups,
    ) {}

    public function root(): string
    {
        return $this->backups->root().'/'.self::DIRECTORY;
    }

    /**
     * @throws ModsxException
     */
    public function pathFor(string $id): string
    {
        if (preg_match('/^\d+$/', $id) !== 1) {
            throw ModsxException::invalidSnapshot($id);
        }

        return $this->root().'/'.$id.'.json';
    }

    /**
     * Snapshot ids, oldest first.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        if (! File::isDirectory($this->root())) {
            return [];
        }

        $ids = [];

        foreach (File::files($this->root()) as $file) {
            $name = $file->getFilename();

            if (preg_match('/^(\d+)\.json$/', $name, $matches) === 1) {
                $ids[] = $matches[1];
            }
        }

        usort($ids, static fn (string $a, string $b): int => (int) $a <=> (int) $b);

        return $ids;
    }

    public function latest(): ?string
    {
        $ids = $this->ids();

        return $ids === [] ? null : $ids[count($ids) - 1];
    }

    public function has(string $id): bool
    {
        return in_array($id, $this->ids(), true);
    }

    public function nextId(): string
    {
        $ids = $this->ids();

        return $this->backups->pad($ids === [] ? 1 : max(array_map('intval', $ids)) + 1);
    }

    /**
     * @return array{snapshot: string, created_at: ?string, comment: ?string, root: ?string, modules: array<string, string>, dependencies: array<string, list<string>>}|null
     *
     * @throws ModsxException
     */
    public function read(string $id): ?array
    {
        $file = $this->pathFor($id);

        if (! File::isFile($file)) {
            return null;
        }

        $decoded = json_decode(File::get($file), true);

        if (! is_array($decoded)) {
            return null;
        }

        return [
            'snapshot' => $id,
            'created_at' => is_string($decoded['created_at'] ?? null) ? $decoded['created_at'] : null,
            'comment' => is_string($decoded['comment'] ?? null) ? $decoded['comment'] : null,
            'root' => is_string($decoded['root'] ?? null) ? $decoded['root'] : null,
            'modules' => $this->safeModules($decoded['modules'] ?? null, $id),
            'dependencies' => $this->safeDependencies($decoded['dependencies'] ?? null),
        ];
    }

    /**
     * @param  array<string, string>  $modules
     * @param  array<string, list<string>>  $dependencies
     *
     * @throws ModsxException
     */
    public function write(string $id, array $modules, array $dependencies, ?string $root, ?string $comment): void
    {
        File::ensureDirectoryExists($this->root());

        File::put($this->pathFor($id), json_encode([
            'snapshot' => $id,
            'created_at' => date(DATE_ATOM),
            'comment' => $comment,
            'root' => $root,
            'modules' => $modules,
            'dependencies' => $dependencies,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    /**
     * @throws ModsxException
     */
    public function delete(string $id): void
    {
        File::delete($this->pathFor($id));
    }

    /**
     * Which versions no snapshot can afford to lose.
     *
     * prune consults this before removing anything: a snapshot naming a
     * version that has been deleted can no longer be rolled back to, and
     * finding that out at rollback time is finding out too late.
     *
     * @return array<string, list<string>> module => versions held
     */
    public function heldVersions(): array
    {
        $held = [];

        foreach ($this->ids() as $id) {
            $snapshot = $this->read($id);

            if ($snapshot === null) {
                continue;
            }

            foreach ($snapshot['modules'] as $module => $version) {
                $held[$module][$version] = true;
            }
        }

        return array_map(
            static fn (array $versions): array => array_keys($versions),
            $held,
        );
    }

    /**
     * Which snapshots name a version that is no longer in the backup tree.
     *
     * @return list<array{snapshot: string, module: string, version: string}>
     */
    public function dangling(): array
    {
        $rows = [];

        foreach ($this->ids() as $id) {
            $snapshot = $this->read($id);

            if ($snapshot === null) {
                continue;
            }

            foreach ($snapshot['modules'] as $module => $version) {
                if (! $this->backups->has($module, $version)) {
                    $rows[] = ['snapshot' => $id, 'module' => $module, 'version' => $version];
                }
            }
        }

        return $rows;
    }

    /**
     * A snapshot is read back the way a manifest is: as something somebody
     * else may have written. A version that is not digits becomes a path in
     * versionPath(), and a module name that is not a name becomes a directory.
     *
     * @return array<string, string>
     *
     * @throws ModsxException
     */
    private function safeModules(mixed $modules, string $id): array
    {
        if (! is_array($modules)) {
            return [];
        }

        $safe = [];

        foreach ($modules as $module => $version) {
            if (! is_string($module) || ! is_string($version)) {
                continue;
            }

            if (preg_match('/^\d+$/', $version) !== 1 || ModuleName::tryMake($module) === null) {
                throw ModsxException::unsafeSnapshotEntry((string) $module, (string) $version, $id);
            }

            $safe[ModuleName::make($module)->studly] = $version;
        }

        ksort($safe);

        return $safe;
    }

    /**
     * @return array<string, list<string>>
     */
    private function safeDependencies(mixed $dependencies): array
    {
        if (! is_array($dependencies)) {
            return [];
        }

        $safe = [];

        foreach ($dependencies as $module => $needs) {
            if (is_string($module) && is_array($needs)) {
                $safe[$module] = array_values(array_filter($needs, 'is_string'));
            }
        }

        return $safe;
    }
}
