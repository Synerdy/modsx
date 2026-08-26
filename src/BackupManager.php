<?php

declare(strict_types=1);

namespace Modsx;

use Illuminate\Support\Facades\File;
use Modsx\Exceptions\ModsxException;
use Symfony\Component\Finder\Finder;
use Throwable;
use ZipArchive;

/**
 * Everything that writes to disk.
 *
 * Both backup and restore assemble their result in a staging directory first
 * and only then move it into place, so an interrupted run leaves either the
 * old state or the new one, not a mixture of the two.
 */
class BackupManager
{
    public function __construct(
        private readonly ModuleLocator $locator,
        private readonly BackupRepository $backups,
    ) {}

    /**
     * Copy a module into a new version.
     *
     * @param  ?string  $comment  Optional free-text note, recorded in the manifest as-is.
     * @return array{version: string, paths: list<string>, target: string}
     *
     * @throws ModsxException
     */
    public function backup(ModuleName|string $name, ?string $comment = null): array
    {
        $name = ModuleName::make($name);
        $paths = $this->locator->paths($name);

        if ($paths === []) {
            throw ModsxException::moduleNotFound($name->studly);
        }

        $version = $this->backups->nextVersion($name);
        $target = $this->backups->versionPath($name, $version);

        // nextVersion() cannot collide by arithmetic, but the directory could
        // still exist - created by hand, or by a second process a moment ago.
        // Merging into it would silently corrupt that backup, so refuse.
        if (File::exists($target)) {
            throw ModsxException::versionAlreadyExists($name->studly, $version);
        }

        $staging = $this->stagingPath($this->backups->pathFor($name));

        try {
            File::ensureDirectoryExists($staging);

            foreach ($paths as $relative) {
                $from = base_path($relative);
                $to = $staging.'/'.$relative;

                File::ensureDirectoryExists(dirname($to));

                if (! File::copyDirectory($from, $to)) {
                    throw ModsxException::copyFailed($from, $to);
                }
            }

            $this->backups->writeManifest($staging, [
                'module' => $name->studly,
                'version' => $version,
                'created_at' => date(DATE_ATOM),
                'prefix' => $this->locator->prefix(),
                'paths' => $paths,
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'modsx_version' => ModsxServiceProvider::version(),
                'comment' => $comment,
            ]);

            if (! File::moveDirectory($staging, $target)) {
                throw ModsxException::copyFailed($staging, $target);
            }
        } catch (Throwable $exception) {
            File::deleteDirectory($staging);

            throw $exception;
        }

        return [
            'version' => $version,
            'paths' => $paths,
            'target' => $target,
        ];
    }

    /**
     * Remove a module's directories from the application.
     *
     * Takes no backup of its own - callers decide whether that has happened.
     *
     * @return list<string> paths removed
     *
     * @throws ModsxException
     */
    public function delete(ModuleName|string $name): array
    {
        $name = ModuleName::make($name);
        $paths = $this->locator->paths($name);

        if ($paths === []) {
            throw ModsxException::moduleNotFound($name->studly);
        }

        foreach ($paths as $relative) {
            File::deleteDirectory(base_path($relative));
        }

        return $paths;
    }

    /**
     * Restore a version into the application, replacing what is there now.
     *
     * @return array{version: string, paths: list<string>}
     *
     * @throws ModsxException
     */
    public function restore(ModuleName|string $name, ?string $version = null): array
    {
        $name = ModuleName::make($name);

        $version ??= $this->backups->latest($name);

        if ($version === null) {
            throw ModsxException::noBackups($name->studly);
        }

        if (! $this->backups->has($name, $version)) {
            throw ModsxException::versionNotFound($name->studly, $version);
        }

        $source = $this->backups->versionPath($name, $version);
        $paths = $this->pathsInBackup($name, $version);

        if ($paths === []) {
            throw ModsxException::emptyBackup($name->studly, $version);
        }

        // Staged inside the project root, not in storage/: the final step is a
        // rename, and rename() fails across filesystems. storage/ is a mounted
        // volume often enough that staging there would break restores on
        // exactly the setups least able to debug them.
        $staging = $this->stagingPath(base_path());

        try {
            // Copy everything out of the backup before touching the application,
            // so a broken or incomplete backup is discovered while the current
            // state is still intact.
            foreach ($paths as $relative) {
                $from = $source.'/'.$relative;

                if (! File::isDirectory($from)) {
                    throw ModsxException::missingInBackup($relative, $version);
                }

                $to = $staging.'/'.$relative;

                File::ensureDirectoryExists(dirname($to));

                if (! File::copyDirectory($from, $to)) {
                    throw ModsxException::copyFailed($from, $to);
                }
            }

            foreach ($paths as $relative) {
                $live = base_path($relative);

                File::deleteDirectory($live);
                File::ensureDirectoryExists(dirname($live));

                if (! File::moveDirectory($staging.'/'.$relative, $live)) {
                    throw ModsxException::restoreFailed($relative, $version, $this->backups->pathFor($name));
                }
            }
        } finally {
            File::deleteDirectory($staging);
        }

        return [
            'version' => $version,
            'paths' => $paths,
        ];
    }

    /**
     * Pack a backup version into a portable .zip, next to the version
     * directory it was built from.
     *
     * The zip is a derived, regenerable artifact, not a new version - unlike
     * a version directory, re-running this always overwrites whatever zip
     * was there before.
     *
     * @return array{version: string, path: string, size_bytes: int}
     *
     * @throws ModsxException
     */
    public function export(ModuleName|string $name, ?string $version = null): array
    {
        if (! extension_loaded('zip')) {
            throw ModsxException::zipExtensionMissing();
        }

        $name = ModuleName::make($name);
        $version ??= $this->backups->latest($name);

        if ($version === null) {
            throw ModsxException::noBackups($name->studly);
        }

        if (! $this->backups->has($name, $version)) {
            throw ModsxException::versionNotFound($name->studly, $version);
        }

        $source = $this->backups->versionPath($name, $version);
        $target = $source.'.zip';
        $staging = $target.'.tmp-'.bin2hex(random_bytes(6));

        $zip = new ZipArchive;

        if ($zip->open($staging, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ModsxException::zipWriteFailed($staging);
        }

        try {
            try {
                foreach (File::allFiles($source) as $file) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));

                    if (! $zip->addFile($file->getPathname(), $relative)) {
                        throw ModsxException::zipWriteFailed($staging);
                    }
                }
            } finally {
                // Closed before any cleanup below, so a failed write doesn't
                // leave the archive handle holding the file open - relevant
                // on Windows, where a locked file can't be deleted.
                $zip->close();
            }

            if (! File::move($staging, $target)) {
                throw ModsxException::copyFailed($staging, $target);
            }
        } catch (Throwable $exception) {
            File::delete($staging);

            throw $exception;
        }

        return [
            'version' => $version,
            'path' => $target,
            'size_bytes' => (int) File::size($target),
        ];
    }

    /**
     * Unpack a .zip produced by export() back into the backup tree, at the
     * module and version its own manifest names.
     *
     * @return array{module: string, version: string, target: string}
     *
     * @throws ModsxException
     */
    public function import(string $zipPath): array
    {
        if (! extension_loaded('zip')) {
            throw ModsxException::zipExtensionMissing();
        }

        if (! File::isFile($zipPath)) {
            throw ModsxException::invalidExportFile($zipPath);
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw ModsxException::invalidExportFile($zipPath);
        }

        $raw = $zip->getFromName(BackupRepository::MANIFEST);
        $manifest = is_string($raw) ? json_decode($raw, true) : null;

        if (
            ! is_array($manifest)
            || ! is_string($manifest['module'] ?? null)
            || ! is_string($manifest['version'] ?? null)
        ) {
            $zip->close();

            throw ModsxException::invalidExportFile($zipPath);
        }

        $name = ModuleName::make($manifest['module']);
        $version = $manifest['version'];
        $target = $this->backups->versionPath($name, $version);

        if (File::exists($target)) {
            $zip->close();

            throw ModsxException::versionAlreadyExists($name->studly, $version);
        }

        $staging = $this->stagingPath($this->backups->pathFor($name));

        try {
            try {
                File::ensureDirectoryExists($staging);

                if (! $zip->extractTo($staging)) {
                    throw ModsxException::copyFailed($zipPath, $staging);
                }
            } finally {
                $zip->close();
            }

            if (! File::moveDirectory($staging, $target)) {
                throw ModsxException::copyFailed($staging, $target);
            }
        } catch (Throwable $exception) {
            File::deleteDirectory($staging);

            throw $exception;
        }

        return [
            'module' => $name->studly,
            'version' => $version,
            'target' => $target,
        ];
    }

    /**
     * Remove all but the newest $keep versions of a module.
     *
     * @return list<string> versions removed, or that would be removed
     *
     * @throws ModsxException
     */
    public function prune(ModuleName|string $name, int $keep, bool $dryRun = false): array
    {
        $name = ModuleName::make($name);
        $keep = max(1, $keep);

        $versions = $this->backups->versions($name);

        if ($versions === []) {
            throw ModsxException::noBackups($name->studly);
        }

        $removable = array_slice($versions, 0, max(0, count($versions) - $keep));

        if (! $dryRun) {
            foreach ($removable as $version) {
                File::deleteDirectory($this->backups->versionPath($name, $version));

                // A version's exported zip (if one was ever made) is a
                // derived artifact of that version - it goes with it.
                File::delete($this->backups->versionPath($name, $version).'.zip');
            }
        }

        return $removable;
    }

    /**
     * Paths a backup version contains, relative to the version directory.
     *
     * Read from the manifest when there is one. Backups created before
     * manifests existed, or assembled by hand, fall back to a scan.
     *
     * @return list<string>
     *
     * @throws ModsxException
     */
    public function pathsInBackup(ModuleName|string $name, string $version): array
    {
        $name = ModuleName::make($name);

        $manifest = $this->backups->manifest($name, $version);

        if (is_array($manifest['paths'] ?? null) && $manifest['paths'] !== []) {
            return array_values(array_filter($manifest['paths'], 'is_string'));
        }

        $source = $this->backups->versionPath($name, $version);

        if (! File::isDirectory($source)) {
            return [];
        }

        $finder = (new Finder)
            ->directories()
            ->in($source)
            ->name([
                $this->locator->prefix().'-'.$name->kebab,
                $this->locator->prefixStudly().$name->studly,
            ])
            ->sortByName();

        $paths = [];

        foreach ($finder as $directory) {
            $paths[] = str_replace('\\', '/', substr($directory->getPathname(), strlen($source) + 1));
        }

        return $paths;
    }

    private function stagingPath(string $parent): string
    {
        return rtrim(str_replace('\\', '/', $parent), '/').'/.modsx-tmp-'.bin2hex(random_bytes(6));
    }
}
