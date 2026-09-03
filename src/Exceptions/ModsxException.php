<?php

declare(strict_types=1);

namespace Modsx\Exceptions;

use RuntimeException;
use Throwable;

class ModsxException extends RuntimeException
{
    public static function invalidModuleName(string $value): self
    {
        return new self(sprintf(
            'Invalid module name [%s]. Names may contain letters and digits only, '.
            'optionally separated by "-", "_" or spaces.',
            $value
        ));
    }

    public static function invalidPrefix(string $value): self
    {
        return new self(sprintf(
            'Invalid modsx.prefix [%s]. The prefix must contain letters and digits only.',
            $value
        ));
    }

    public static function moduleNotFound(string $module): self
    {
        return new self(sprintf('Module [%s] was not found in the application.', $module));
    }

    /**
     * Files name the module, but no directory does.
     *
     * A module is a set of directories - that is the whole premise - and a
     * file named for one belongs to it rather than making it. Saying only
     * "not found" would be baffling with config/modsx-blog.php sitting right
     * there, so this names what was found and what is missing.
     *
     * @param  list<string>  $files
     */
    public static function moduleIsOnlyFiles(string $module, array $files): self
    {
        return new self(sprintf(
            'Module [%s] has no directories, only files: %s. A module is a set of '.
            'directories, and a file named for one belongs to it rather than making '.
            'it - which is why "%s" is reported by modsx:doctor as naming a module '.
            'that does not exist. Create a directory for it: php artisan modsx:scaffold %s',
            $module,
            implode(', ', $files),
            $files[0] ?? '',
            $module
        ));
    }

    public static function noBackups(string $module): self
    {
        return new self(sprintf('Module [%s] has no backups.', $module));
    }

    public static function versionNotFound(string $module, string $version): self
    {
        return new self(sprintf('Version [%s] of module [%s] does not exist.', $version, $module));
    }

    public static function invalidVersion(string $version): self
    {
        return new self(sprintf(
            'Invalid version [%s]. A version is a number, as written by every backup '.
            'this package takes - 0001, 0002 and so on.',
            $version
        ));
    }

    /**
     * A path read out of a manifest that would not stay inside the project.
     *
     * The manifest of an imported version was written by whoever made the zip,
     * so restoring one means writing files to paths that came from outside.
     */
    public static function unsafeManifestPath(string $path, string $version): self
    {
        return new self(sprintf(
            'Backup version [%s] lists the path [%s], which does not stay inside the '.
            'project. A manifest may only name relative paths, and never one '.
            'containing "..". Refusing to touch it.',
            $version,
            $path
        ));
    }

    public static function versionAlreadyExists(string $module, string $version): self
    {
        return new self(sprintf(
            'Refusing to write backup [%s] of module [%s]: the directory already exists. '.
            'Move it aside or remove it, then run the command again.',
            $version,
            $module
        ));
    }

    public static function emptyBackup(string $module, string $version): self
    {
        return new self(sprintf(
            'Backup [%s] of module [%s] contains no module directories.',
            $version,
            $module
        ));
    }

    public static function missingInBackup(string $path, string $version): self
    {
        return new self(sprintf(
            'Backup version [%s] is incomplete: [%s] is listed in the manifest but missing on disk.',
            $version,
            $path
        ));
    }

    public static function restoreFailed(string $path, string $version, string $backupDirectory): self
    {
        return new self(sprintf(
            'Restore of [%s] from version [%s] failed while moving it into place. '.
            'The module may be partially restored. Every version, including the one '.
            'taken before this restore started, is still in [%s].',
            $path,
            $version,
            $backupDirectory
        ));
    }

    public static function copyFailed(string $from, string $to): self
    {
        return new self(sprintf('Failed to copy [%s] to [%s].', $from, $to));
    }

    public static function restoreInterrupted(string $version, string $backupDirectory, Throwable $previous): self
    {
        return new self(sprintf(
            'Restore of version [%s] was interrupted: %s. The application was put '.
            'back the way it was before the restore started. Every version, '.
            'including the one taken just now, is still in [%s].',
            $version,
            $previous->getMessage(),
            $backupDirectory
        ), previous: $previous);
    }

    public static function zipExtensionMissing(): self
    {
        return new self(
            'The PHP zip extension is required for modsx:export and modsx:import '.
            'but is not installed. Enable ext-zip and try again.'
        );
    }

    public static function invalidExportFile(string $path): self
    {
        return new self(sprintf(
            'The file [%s] is not a valid Modsx export: its modsx.json manifest is missing or unreadable.',
            $path
        ));
    }

    public static function zipWriteFailed(string $path): self
    {
        return new self(sprintf('Failed to write the zip archive [%s].', $path));
    }

    public static function invalidScaffoldPath(string $template): self
    {
        return new self(sprintf(
            'Invalid modsx.scaffold entry [%s]: entries may not contain "..".',
            $template
        ));
    }

    /**
     * The same fault as invalidScaffoldPath, but typed rather than configured -
     * worth its own message, so it does not send the reader to a config file
     * they never touched.
     */
    public static function invalidPath(string $path): self
    {
        return new self(sprintf(
            'Invalid path [%s]. Give a directory inside the project, such as '.
            '"resources/css" or "app/Services"; it may not contain "..".',
            $path
        ));
    }

    public static function unknownGenerator(string $generator): self
    {
        return new self(sprintf(
            'There is no [make:%s] command. Run "php artisan list make" to see what is available.',
            $generator
        ));
    }

    /**
     * The name given to modsx:make carries no module.
     *
     * Both "/" and "." divide it, whichever suits the generator: a view name
     * reads with dots in Laravel's own documentation, a class path with slashes.
     *
     * The mention of the shell is not padding: a backslash written without
     * quotes is removed by the shell before the process starts, so
     * "Blog\PostController" arrives here as "BlogPostController" with nothing
     * left to detect. This message is the only place that trap can be named.
     */
    public static function missingModuleSegment(string $name): self
    {
        return new self(sprintf(
            'The name [%s] does not say which module it belongs to. Put the module '.
            'first, separated by "/" or "." - Blog/PostController, or blog.create the '.
            'way a view name reads. A backslash works too, but a POSIX shell removes '.
            'an unquoted one before this command ever sees it.',
            $name
        ));
    }

    public static function caseCollision(string $requested, string $existing): self
    {
        return new self(sprintf(
            'Refusing to back up module [%s]: the backup directory already holds [%s], '.
            'which differs only in letter case. On Windows and macOS those are the same '.
            'directory, so both modules would share one backup tree and a restore could '.
            'hand back the wrong module. Rename one of them so the two names differ by '.
            'more than case.',
            $requested,
            $existing
        ));
    }

    public static function invalidSnapshot(string $id): self
    {
        return new self(sprintf(
            'Invalid snapshot [%s]. A snapshot is a number, as written by every '.
            'modsx:snapshot run - 0001, 0002 and so on.',
            $id
        ));
    }

    public static function snapshotNotFound(string $id): self
    {
        return new self(sprintf('Snapshot [%s] does not exist.', $id));
    }

    public static function noSnapshots(): self
    {
        return new self('No snapshots have been taken yet. Run modsx:snapshot to take one.');
    }

    /**
     * A module and version read out of a snapshot that could not have been
     * written by this package.
     *
     * A snapshot file is as editable as any other, and both halves of an entry
     * become a path: the module names a directory, the version names one
     * inside it. Neither is trusted on the way back in.
     */
    public static function unsafeSnapshotEntry(string $module, string $version, string $id): self
    {
        return new self(sprintf(
            'Snapshot [%s] names module [%s] at version [%s], which is not a module '.
            'and version this package could have written. Refusing to read it.',
            $id,
            $module,
            $version
        ));
    }

    /**
     * @param  list<array{module: string, version: string}>  $missing
     */
    public static function snapshotIncomplete(string $id, array $missing): self
    {
        $lines = array_map(
            static fn (array $row): string => sprintf('%s %s', $row['module'], $row['version']),
            $missing
        );

        return new self(sprintf(
            'Snapshot [%s] cannot be rolled back to: %d of the versions it names are '.
            'no longer in the backup tree (%s). Nothing has been changed. They were '.
            'most likely removed by modsx:prune --force.',
            $id,
            count($missing),
            implode(', ', $lines)
        ));
    }

    public static function noModules(): self
    {
        return new self(
            'No modules found in the application, so there is nothing to snapshot.'
        );
    }

    /**
     * A rollback that failed part-way and put back what it had already moved.
     */
    public static function rollbackReverted(string $id, string $safety, string $reason): self
    {
        return new self(sprintf(
            'Rolling back to snapshot [%s] failed and was undone: %s The modules already '.
            'restored were put back to where snapshot [%s] found them, which is also the '.
            'snapshot to roll back to if anything still looks wrong.',
            $id,
            rtrim($reason, '.').'.',
            $safety
        ));
    }
}
