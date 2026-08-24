<?php

declare(strict_types=1);

namespace Modsx;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\File;

/**
 * Reads and describes the backup tree.
 *
 * Every method tolerates a missing backup directory: on a fresh installation
 * nothing here exists yet, and that is not an error.
 */
class BackupRepository
{
    public const MANIFEST = 'modsx.json';

    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function root(): string
    {
        $path = (string) $this->config->get('modsx.backup_path', base_path('modsx-backups'));

        return rtrim(str_replace('\\', '/', $path), '/');
    }

    public function pathFor(ModuleName|string $name): string
    {
        return $this->root().'/'.ModuleName::make($name)->studly;
    }

    public function versionPath(ModuleName|string $name, string $version): string
    {
        return $this->pathFor($name).'/'.$version;
    }

    /**
     * Versions of a module, ordered numerically ascending.
     *
     * Ordering is computed here rather than taken from the filesystem: readdir
     * order is not sorted on every filesystem, and getting this wrong is how a
     * backup ends up written over an existing one.
     *
     * @return list<string>
     */
    public function versions(ModuleName|string $name): array
    {
        $path = $this->pathFor($name);

        if (! File::isDirectory($path)) {
            return [];
        }

        $versions = [];

        foreach (File::directories($path) as $directory) {
            $basename = basename($directory);

            if (preg_match('/^\d+$/', $basename) === 1) {
                $versions[] = $basename;
            }
        }

        usort($versions, static fn (string $a, string $b): int => (int) $a <=> (int) $b);

        return $versions;
    }

    public function latest(ModuleName|string $name): ?string
    {
        $versions = $this->versions($name);

        return $versions === [] ? null : $versions[count($versions) - 1];
    }

    public function has(ModuleName|string $name, string $version): bool
    {
        return in_array($version, $this->versions($name), true);
    }

    /**
     * The next free version number.
     *
     * Derived from the highest existing number, not from the last one listed,
     * so a manually deleted version in the middle of the sequence cannot make
     * the counter go backwards.
     */
    public function nextVersion(ModuleName|string $name): string
    {
        $versions = $this->versions($name);

        $next = $versions === []
            ? 1
            : max(array_map('intval', $versions)) + 1;

        return $this->pad($next);
    }

    public function pad(int $number): string
    {
        $padding = max(1, (int) $this->config->get('modsx.version_padding', 4));

        return str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
    }

    /**
     * Modules that have at least one backup.
     *
     * @return list<string>
     */
    public function modules(): array
    {
        if (! File::isDirectory($this->root())) {
            return [];
        }

        $names = [];

        foreach (File::directories($this->root()) as $directory) {
            $name = ModuleName::tryMake(basename($directory));

            if ($name !== null && $this->versions($name) !== []) {
                $names[] = $name->studly;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function manifest(ModuleName|string $name, string $version): ?array
    {
        $file = $this->versionPath($name, $version).'/'.self::MANIFEST;

        if (! File::isFile($file)) {
            return null;
        }

        $decoded = json_decode(File::get($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function writeManifest(string $versionDirectory, array $manifest): void
    {
        File::put(
            $versionDirectory.'/'.self::MANIFEST,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
        );
    }

    /**
     * Human-facing description of one version, for listings.
     *
     * @return array{version: string, created_at: ?string, paths: int}
     */
    public function describe(ModuleName|string $name, string $version): array
    {
        $path = $this->versionPath($name, $version);
        $manifest = $this->manifest($name, $version);

        $createdAt = is_string($manifest['created_at'] ?? null)
            ? $manifest['created_at']
            : (File::isDirectory($path) ? date(DATE_ATOM, (int) filemtime($path)) : null);

        $paths = is_array($manifest['paths'] ?? null)
            ? count($manifest['paths'])
            : count(File::directories($path));

        return [
            'version' => $version,
            'created_at' => $createdAt,
            'paths' => $paths,
        ];
    }
}
