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

    public static function noBackups(string $module): self
    {
        return new self(sprintf('Module [%s] has no backups.', $module));
    }

    public static function versionNotFound(string $module, string $version): self
    {
        return new self(sprintf('Version [%s] of module [%s] does not exist.', $version, $module));
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
}
