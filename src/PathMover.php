<?php

declare(strict_types=1);

namespace Modsx;

use Illuminate\Support\Facades\File;

/**
 * Moving a file or directory when another process may be holding it open.
 *
 * Every write this package makes is staged first and moved into place at the
 * end, so an interrupted run cannot leave a half-written result. On Windows
 * that last step is where it breaks: rename() fails with ERROR_ACCESS_DENIED
 * while any other process holds a handle inside the directory, and a project
 * full of freshly written files is exactly what a file watcher is watching.
 * Vite's chokidar is the one people hit, but VS Code, the Windows Search
 * indexer, antivirus and folder sync all do the same thing.
 *
 * Retrying alone does not fix it. A watcher's handle is not transient - it
 * holds for as long as the watch does - so the retries here are for locks that
 * genuinely pass, an antivirus reading a file it has just seen.
 *
 * Beyond that the two callers want different things, and the difference
 * decides whether copying is a safe substitute:
 *
 * - place() cares that the destination ends up complete. The source is a
 *   staging directory nobody else refers to, so copying and then failing to
 *   delete it costs a leftover directory and nothing more.
 *
 * - move() cares that the source is gone, because something else is about to
 *   be put where it stood. Copying cannot promise that: deleting the original
 *   can fail halfway, which on Windows is exactly what a held file does, and
 *   the result would be a directory emptied of everything except the one file
 *   nobody could touch. So move() renames or it fails, and the caller is left
 *   with the application exactly as it found it.
 */
class PathMover
{
    private const ATTEMPTS = 3;

    private const BACKOFF_MICROSECONDS = 50_000;

    /**
     * Put a directory at the destination, whole, by whatever means.
     */
    public function placeDirectory(string $from, string $to): bool
    {
        if (! File::isDirectory($from)) {
            return false;
        }

        if ($this->rename($from, $to)) {
            return true;
        }

        // Suppressed rather than allowed to surface: Laravel turns a copy
        // warning into an exception, and this is a path that is allowed to
        // fail and say so - putBack() calls it while already handling one
        // failure, where a second thrown from here would replace the first.
        if (! @File::copyDirectory($from, $to)) {
            // Half a directory at the destination is worse than none: what is
            // left behind must never look like something restorable.
            File::deleteDirectory($to);

            return false;
        }

        // Best effort, and deliberately not part of the answer. Whatever
        // refused the rename may equally refuse this, and a leftover staging
        // directory is untidy rather than wrong - modsx:doctor reports them.
        File::deleteDirectory($from);

        return true;
    }

    public function placeFile(string $from, string $to): bool
    {
        if (! File::isFile($from)) {
            return false;
        }

        if ($this->rename($from, $to)) {
            return true;
        }

        if (! @File::copy($from, $to)) {
            File::delete($to);

            return false;
        }

        File::delete($from);

        return true;
    }

    /**
     * Move a path out of the way, or report that it could not be done.
     *
     * No copy fallback here, on purpose: see the note on the class.
     */
    public function moveDirectory(string $from, string $to): bool
    {
        return File::isDirectory($from) && $this->rename($from, $to);
    }

    public function moveFile(string $from, string $to): bool
    {
        return File::isFile($from) && $this->rename($from, $to);
    }

    /**
     * rename(), given a few chances at a lock that might let go.
     */
    private function rename(string $from, string $to): bool
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            if (@rename($from, $to)) {
                return true;
            }

            if ($attempt < self::ATTEMPTS) {
                usleep(self::BACKOFF_MICROSECONDS * $attempt);
            }
        }

        return false;
    }
}
