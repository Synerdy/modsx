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
    /**
     * Where archived-only content lives inside a version directory.
     *
     * Kept apart from the restorable paths on purpose: restore() reads the
     * manifest's "paths" and "files" and nothing else, so what sits in here
     * cannot be put back by accident. The leading underscore also keeps it
     * clear of the prefix patterns pathsInBackup() falls back to scanning for.
     */
    public const ARCHIVE_DIRECTORY = '_archive';

    public function __construct(
        private readonly ModuleLocator $locator,
        private readonly BackupRepository $backups,
        private readonly ModuleDiffer $differ,
        private readonly ModuleState $state,
        private readonly SnapshotRepository $snapshots,
    ) {}

    /**
     * Copy a module into a new version.
     *
     * @param  ?string  $comment  Optional free-text note, recorded in the manifest as-is.
     * @param  bool  $skipUnchanged  Return the newest version untouched when the module is identical to it.
     * @return array{version: string, paths: list<string>, files: list<string>, archived: list<string>, target: string, skipped: bool}
     *
     * @throws ModsxException
     */
    public function backup(ModuleName|string $name, ?string $comment = null, bool $skipUnchanged = false): array
    {
        $name = ModuleName::make($name);
        $paths = $this->locator->paths($name);

        if ($paths === []) {
            throw $this->nothingToActOn($name);
        }

        $files = $this->locator->files($name);
        $archived = $this->locator->migrations($name);

        if ($skipUnchanged) {
            $unchanged = $this->matchesNewestVersion($name, $paths, $files);

            if ($unchanged !== null) {
                // Nothing was written, but the tree does match this version,
                // which is exactly what the pointer records - and a run that
                // skipped is the cheapest chance to correct a stale one.
                $this->state->record($name, $unchanged, 'backup');

                return [
                    'version' => $unchanged,
                    'paths' => $paths,
                    'files' => $files,
                    'archived' => $archived,
                    'target' => $this->backups->versionPath($name, $unchanged),
                    'skipped' => true,
                ];
            }
        }

        // Before reading any version numbers: on a case-insensitive filesystem
        // they would come from the colliding module's tree, not this one's.
        $collision = $this->backups->collidingName($name);

        if ($collision !== null) {
            throw ModsxException::caseCollision($name->studly, $collision);
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

            $this->copyFilesInto($files, base_path(), $staging);

            // Migrations go somewhere restore() never looks. They are kept so
            // an old schema can be read back, not so it can be put back: this
            // package does not touch the database, and returning an old
            // migration file to a database that has moved on would leave the
            // two disagreeing with nothing to say so.
            $this->copyFilesInto($archived, base_path(), $staging.'/'.self::ARCHIVE_DIRECTORY);

            $this->backups->writeManifest($staging, [
                'module' => $name->studly,
                'version' => $version,
                'created_at' => date(DATE_ATOM),
                'prefix' => $this->locator->prefix(),
                'paths' => $paths,
                'files' => $files,
                'archived' => $archived,
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

        $this->state->record($name, $version, 'backup');

        return [
            'version' => $version,
            'paths' => $paths,
            'files' => $files,
            'archived' => $archived,
            'target' => $target,
            'skipped' => false,
        ];
    }

    /**
     * The filename modsx:export writes, and modsx:prune removes with its
     * version. One place, so the two cannot disagree.
     */
    private function exportName(ModuleName $name, string $version): string
    {
        return $name->studly.'-'.$version.'.zip';
    }

    /**
     * Why there is nothing to back up or remove.
     *
     * A module is a set of directories, so no directory means no module. But
     * "not found" reads as nonsense to someone looking at config/modsx-blog.php,
     * and that case has an answer worth giving: the file belongs to a module
     * rather than making one, which is the same thing modsx:doctor says when it
     * lists files naming a module that does not exist.
     */
    private function nothingToActOn(ModuleName $name): ModsxException
    {
        $files = $this->locator->files($name);

        return $files === []
            ? ModsxException::moduleNotFound($name->studly)
            : ModsxException::moduleIsOnlyFiles($name->studly, $files);
    }

    /**
     * The newest version's number when the module is byte-for-byte identical
     * to it, or null when there is nothing to compare against or it differs.
     *
     * Archived migrations are left out on purpose: they are not part of the
     * state a restore would put back, so a change to one is not a reason to
     * take another copy of everything else.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $files
     */
    private function matchesNewestVersion(ModuleName $name, array $paths, array $files): ?string
    {
        $newest = $this->backups->latest($name);

        if ($newest === null) {
            return null;
        }

        $identical = $this->differ->identical(
            $this->differ->fingerprint(base_path(), $paths, $files),
            $this->differ->fingerprint(
                $this->backups->versionPath($name, $newest),
                $this->pathsInBackup($name, $newest),
                $this->filesInBackup($name, $newest),
            ),
        );

        return $identical ? $newest : null;
    }

    /**
     * Remove a module's directories and files from the application.
     *
     * Takes no backup of its own - callers decide whether that has happened.
     *
     * Migrations are deliberately left where they are and reported back
     * instead: their tables are still in the database, and removing the file
     * that documents them would leave the schema with nothing explaining it.
     *
     * @return array{paths: list<string>, files: list<string>, migrations: list<string>}
     *
     * @throws ModsxException
     */
    public function delete(ModuleName|string $name): array
    {
        $name = ModuleName::make($name);
        $paths = $this->locator->paths($name);

        if ($paths === []) {
            throw $this->nothingToActOn($name);
        }

        $files = $this->locator->files($name);
        $migrations = $this->locator->migrations($name);

        foreach ($paths as $relative) {
            File::deleteDirectory(base_path($relative));
        }

        foreach ($files as $relative) {
            File::delete(base_path($relative));
        }

        // The pointer describes a working tree, and there is no longer one to
        // describe. The versions stay where they are, so the module can be
        // restored and will point somewhere again.
        $this->state->forget($name);

        return [
            'paths' => $paths,
            'files' => $files,
            'migrations' => $migrations,
        ];
    }

    /**
     * Restore a version into the application, replacing what is there now.
     *
     * Reads only the restorable half of the backup - the manifest's paths and
     * files. Archived migrations live under a key this method never touches
     * and a directory it never walks, so there is no flag to get wrong.
     *
     * @return array{version: string, paths: list<string>, files: list<string>}
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
        $files = $this->filesInBackup($name, $version);

        if ($paths === [] && $files === []) {
            throw ModsxException::emptyBackup($name->studly, $version);
        }

        // Staged inside the project root, not in storage/: the final step is a
        // rename, and rename() fails across filesystems. storage/ is a mounted
        // volume often enough that staging there would break restores on
        // exactly the setups least able to debug them.
        $staging = $this->stagingPath(base_path());
        $previous = $this->stagingPath(base_path());

        try {
            // 1. Copy everything out of the backup before touching the
            //    application, so a broken or incomplete backup is discovered
            //    while the current state is still intact.
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

            foreach ($files as $relative) {
                $from = $source.'/'.$relative;

                if (! File::isFile($from)) {
                    throw ModsxException::missingInBackup($relative, $version);
                }

                $to = $staging.'/'.$relative;

                File::ensureDirectoryExists(dirname($to));

                if (! File::copy($from, $to)) {
                    throw ModsxException::copyFailed($from, $to);
                }
            }

            $displaced = [];

            try {
                // 2. Move the entire current state aside in one pass, rather
                //    than deleting each path just before replacing it. The old
                //    state ends up whole, in one place, so a failure in step 3
                //    can be undone instead of leaving the module half restored.
                //    This also removes whatever the version did not contain:
                //    anything moved aside and not put back is gone, which is
                //    what "restore this exact state" has to mean.
                foreach ($this->locator->paths($name) as $relative) {
                    $this->displace($relative, $previous, directory: true);
                    $displaced[$relative] = true;
                }

                foreach ($this->locator->files($name) as $relative) {
                    $this->displace($relative, $previous, directory: false);
                    $displaced[$relative] = false;
                }

                // 3. Put the restored state in place.
                foreach ($paths as $relative) {
                    $live = base_path($relative);

                    File::ensureDirectoryExists(dirname($live));

                    if (! File::moveDirectory($staging.'/'.$relative, $live)) {
                        throw ModsxException::restoreFailed($relative, $version, $this->backups->pathFor($name));
                    }
                }

                foreach ($files as $relative) {
                    $live = base_path($relative);

                    File::ensureDirectoryExists(dirname($live));

                    if (! File::move($staging.'/'.$relative, $live)) {
                        throw ModsxException::restoreFailed($relative, $version, $this->backups->pathFor($name));
                    }
                }
            } catch (Throwable $exception) {
                $this->putBack($displaced, $previous);

                // Filesystem trouble surfaces as a raw PHP warning turned into
                // an ErrorException by Laravel; on its own that reaches the
                // user as a stack trace. Commands catch ModsxException, so wrap
                // it and say plainly that nothing was lost.
                throw $exception instanceof ModsxException
                    ? $exception
                    : ModsxException::restoreInterrupted($version, $this->backups->pathFor($name), $exception);
            }
        } finally {
            File::deleteDirectory($staging);
            File::deleteDirectory($previous);
        }

        $this->state->record($name, $version, 'restore');

        return [
            'version' => $version,
            'paths' => $paths,
            'files' => $files,
        ];
    }

    /**
     * Move one live path out of the application and into the holding area.
     *
     * @throws ModsxException
     */
    private function displace(string $relative, string $holding, bool $directory): void
    {
        $live = base_path($relative);
        $aside = $holding.'/'.$relative;

        File::ensureDirectoryExists(dirname($aside));

        $moved = $directory
            ? File::moveDirectory($live, $aside)
            : File::move($live, $aside);

        if (! $moved) {
            throw ModsxException::copyFailed($live, $aside);
        }
    }

    /**
     * Undo displace() for everything already moved aside, after a restore
     * failed partway through putting the new state in place.
     *
     * @param  array<string, bool>  $displaced  relative path => is a directory
     */
    private function putBack(array $displaced, string $holding): void
    {
        foreach ($displaced as $relative => $directory) {
            $live = base_path($relative);
            $aside = $holding.'/'.$relative;

            if ($directory) {
                File::deleteDirectory($live);
                File::ensureDirectoryExists(dirname($live));
                File::moveDirectory($aside, $live);

                continue;
            }

            File::delete($live);
            File::ensureDirectoryExists(dirname($live));
            File::move($aside, $live);
        }
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

        // Named for the module as well as the version. A zip is the form a
        // module travels in, and "0002.zip" says nothing at all once it has
        // been copied anywhere else.
        $target = $this->backups->pathFor($name).'/'.$this->exportName($name, $version);
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

        $collision = $this->backups->collidingName($name);

        if ($collision !== null) {
            $zip->close();

            throw ModsxException::caseCollision($name->studly, $collision);
        }

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

        // Deliberately no state recorded: importing adds a version to the
        // backup tree and never touches the application, so the working tree
        // did not come from it. Saying otherwise would be the one kind of lie
        // the pointer must not tell. modsx:restore is what puts it to use.
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

        // A snapshot names versions across several modules at once, and one of
        // them going missing is only discovered at the rollback that needed
        // it - too late to be useful. They are held back here, the way a tag
        // keeps a commit from being collected.
        //
        // Deliberately with no way to override it from here. Everywhere else
        // in this package --force means "do not ask me", and letting it also
        // mean "ignore a safeguard" would let a scripted prune quietly strand
        // every snapshot that named these versions. Releasing them is
        // modsx:snapshotprune's job: let the snapshot go, and they follow.
        $removable = array_values(array_diff($removable, $this->heldFromPrune($name, $keep)));

        if (! $dryRun) {
            foreach ($removable as $version) {
                File::deleteDirectory($this->backups->versionPath($name, $version));

                // A version's exported zip (if one was ever made) is a
                // derived artifact of that version - it goes with it. The
                // older name is swept too, so a tree written before exports
                // carried the module name does not keep them for ever.
                File::delete($this->backups->pathFor($name).'/'.$this->exportName($name, $version));
                File::delete($this->backups->versionPath($name, $version).'.zip');
            }
        }

        return $removable;
    }

    /**
     * Versions old enough to prune that a snapshot is holding on to.
     *
     * Public because prune must not be the only thing that knows: a listing
     * that removed fewer versions than the age rule allows has to be able to
     * say which ones it left, and why, rather than quietly doing less than it
     * appeared to offer.
     *
     * @return list<string>
     *
     * @throws ModsxException
     */
    public function heldFromPrune(ModuleName|string $name, int $keep): array
    {
        $name = ModuleName::make($name);
        $versions = $this->backups->versions($name);

        $removable = array_slice($versions, 0, max(0, count($versions) - max(1, $keep)));
        $held = $this->snapshots->heldVersions()[$name->studly] ?? [];

        return array_values(array_intersect($removable, $held));
    }

    /**
     * The paths a manifest lists, once each has been shown to stay inside the
     * project.
     *
     * Restoring a version writes to base_path() of everything the manifest
     * names, and a manifest is not always ours: modsx:import takes one out of
     * a zip that somebody else built, and modsx:export is documented as the
     * way a module travels between projects. An entry of "../../.env" would be
     * written exactly there.
     *
     * A bad entry stops the whole version rather than being quietly dropped.
     * Skipping it would restore a module missing a piece, and say nothing.
     *
     * @param  array<mixed>  $paths
     * @return list<string>
     *
     * @throws ModsxException
     */
    private static function safePaths(array $paths, string $version): array
    {
        $safe = [];

        foreach (array_filter($paths, 'is_string') as $path) {
            $normalised = str_replace('\\', '/', $path);

            if (
                $normalised === ''
                || str_contains($normalised, '..')
                || str_starts_with($normalised, '/')
                // A Windows drive letter is absolute too, and survives a
                // manifest written on one platform and read on another.
                || preg_match('#^[A-Za-z]:#', $normalised) === 1
            ) {
                throw ModsxException::unsafeManifestPath($path, $version);
            }

            $safe[] = $normalised;
        }

        return $safe;
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
            return self::safePaths($manifest['paths'], $version);
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

    /**
     * Files a backup version contains alongside its directories.
     *
     * Manifest only, with no fallback scan: a backup written before files were
     * supported simply has none, and guessing which loose files in an old
     * version directory were once module files is not something a restore
     * should do.
     *
     * @return list<string>
     */
    public function filesInBackup(ModuleName|string $name, string $version): array
    {
        $manifest = $this->backups->manifest($name, $version);

        if (! is_array($manifest['files'] ?? null)) {
            return [];
        }

        return self::safePaths($manifest['files'], $version);
    }

    /**
     * Migrations kept in a version for reference. Reporting only - nothing in
     * this class restores or deletes them.
     *
     * @return list<string>
     */
    public function archivedInBackup(ModuleName|string $name, string $version): array
    {
        $manifest = $this->backups->manifest($name, $version);

        if (! is_array($manifest['archived'] ?? null)) {
            return [];
        }

        return self::safePaths($manifest['archived'], $version);
    }

    /**
     * @param  list<string>  $files  paths relative to $from
     *
     * @throws ModsxException
     */
    private function copyFilesInto(array $files, string $from, string $to): void
    {
        foreach ($files as $relative) {
            $source = rtrim(str_replace('\\', '/', $from), '/').'/'.$relative;
            $target = $to.'/'.$relative;

            File::ensureDirectoryExists(dirname($target));

            if (! File::copy($source, $target)) {
                throw ModsxException::copyFailed($source, $target);
            }
        }
    }

    private function stagingPath(string $parent): string
    {
        return rtrim(str_replace('\\', '/', $parent), '/').'/.modsx-tmp-'.bin2hex(random_bytes(6));
    }
}
