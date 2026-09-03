<?php

declare(strict_types=1);

namespace Modsx;

use Illuminate\Support\Facades\File;

/**
 * Which backup version a module's working tree came from.
 *
 * This never decides whether a module exists - that stays what it has always
 * been, a directory named by the convention, found by ModuleLocator, which
 * knows nothing about this file. A module made by hand has no state here and
 * is not supposed to: "which version did this come from" has no answer for it,
 * and reporting none is the truthful result rather than a gap.
 *
 * Delete every one of these files and the package behaves exactly as it did
 * before they existed. That is the property to preserve if anything here is
 * ever extended.
 *
 * It lives beside the versions it points at, in modsx-backups/<Module>/,
 * because it means nothing without them: when they are deleted, it has to go
 * with them, and anywhere else that becomes a separate piece of cleanup that
 * somebody has to remember. It cannot live inside the module either - it would
 * be part of the module, so a backup would copy it, and version 0004 would
 * contain a file claiming the module is at 0003.
 */
class ModuleState
{
    public const FILE = 'modsx-state.json';

    public function __construct(
        private readonly BackupRepository $backups,
    ) {}

    public function path(ModuleName|string $name): string
    {
        return $this->backups->pathFor($name).'/'.self::FILE;
    }

    /**
     * @return array{current: string, at: ?string, by: ?string}|null
     */
    public function read(ModuleName|string $name): ?array
    {
        $file = $this->path($name);

        if (! File::isFile($file)) {
            return null;
        }

        $decoded = json_decode(File::get($file), true);

        if (! is_array($decoded) || ! is_string($decoded['current'] ?? null)) {
            return null;
        }

        // Read back rather than trusted: this file is as editable as any other,
        // and a version is a path segment everywhere else in this package.
        // Anything but digits is not a version this package ever wrote, so it
        // is treated as no pointer at all.
        if (preg_match('/^\d+$/', $decoded['current']) !== 1) {
            return null;
        }

        return [
            'current' => $decoded['current'],
            'at' => is_string($decoded['at'] ?? null) ? $decoded['at'] : null,
            'by' => is_string($decoded['by'] ?? null) ? $decoded['by'] : null,
        ];
    }

    public function current(ModuleName|string $name): ?string
    {
        return $this->read($name)['current'] ?? null;
    }

    /**
     * Record the version the working tree now matches.
     *
     * @param  string  $by  what put it there, for the explanation a listing can give
     */
    public function record(ModuleName|string $name, string $version, string $by): void
    {
        $directory = $this->backups->pathFor($name);

        // Writing a pointer where there are no versions would create a backup
        // directory holding nothing but a note about versions that do not
        // exist. Every caller records after writing or reading one, so this
        // only skips a case that should not arise.
        if (! File::isDirectory($directory)) {
            return;
        }

        File::put($this->path($name), json_encode([
            'current' => $version,
            'at' => date(DATE_ATOM),
            'by' => $by,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    public function forget(ModuleName|string $name): void
    {
        File::delete($this->path($name));
    }
}
