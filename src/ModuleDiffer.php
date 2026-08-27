<?php

declare(strict_types=1);

namespace Modsx;

use Illuminate\Support\Facades\File;

/**
 * Compares two copies of a module, file by file, using a content hash.
 *
 * Comparing directory names would report "no changes" for a module whose every
 * file had been rewritten, which is the opposite of what anyone asking for a
 * diff wants to know.
 *
 * Takes the paths it should look at rather than resolving them itself, so it
 * can be pointed at the application on one side and a backup version on the
 * other - and so it depends on nothing, which is what lets both modsx:diff and
 * BackupManager use it without either depending on the other.
 */
class ModuleDiffer
{
    /**
     * Map every file under the given directories and files to a content hash.
     *
     * Keys are relative to $root, so the two sides of a comparison line up: a
     * backup stores each path under the same relative path it has in the
     * application.
     *
     * @param  list<string>  $directories
     * @param  list<string>  $files
     * @return array<string, string>
     */
    public function fingerprint(string $root, array $directories, array $files = []): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $map = [];

        foreach ($directories as $relative) {
            $directory = $root.'/'.$relative;

            if (! File::isDirectory($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                $absolute = str_replace('\\', '/', $file->getPathname());

                $map[substr($absolute, strlen($root) + 1)] = (string) md5_file($file->getPathname());
            }
        }

        foreach ($files as $relative) {
            $path = $root.'/'.$relative;

            if (File::isFile($path)) {
                $map[$relative] = (string) md5_file($path);
            }
        }

        ksort($map);

        return $map;
    }

    /**
     * @param  array<string, string>  $current
     * @param  array<string, string>  $backup
     * @return array{added: list<string>, removed: list<string>, modified: list<string>, unchanged: int}
     */
    public function compare(array $current, array $backup): array
    {
        $added = [];
        $removed = [];
        $modified = [];
        $unchanged = 0;

        foreach ($current as $path => $hash) {
            if (! array_key_exists($path, $backup)) {
                $added[] = $path;
            } elseif ($backup[$path] !== $hash) {
                $modified[] = $path;
            } else {
                $unchanged++;
            }
        }

        foreach ($backup as $path => $hash) {
            if (! array_key_exists($path, $current)) {
                $removed[] = $path;
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * @param  array<string, string>  $current
     * @param  array<string, string>  $backup
     */
    public function identical(array $current, array $backup): bool
    {
        return $current === $backup;
    }
}
