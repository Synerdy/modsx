<?php

declare(strict_types=1);

namespace Modsx\Exceptions;

use RuntimeException;

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
}
