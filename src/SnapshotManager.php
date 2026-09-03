<?php

declare(strict_types=1);

namespace Modsx;

use Modsx\Exceptions\ModsxException;
use Throwable;

/**
 * Taking and rolling back to a snapshot of the whole project.
 *
 * What a rollback guarantees, stated exactly, because the difference matters:
 *
 * 1. Every version the snapshot names is checked to still be in the backup
 *    tree before anything in the application is touched. This is the failure
 *    that actually happens - a version pruned away - and it is caught while
 *    the application is still whole.
 * 2. A snapshot of the current state is taken first, so there is always a
 *    named way back. It is cheap: a module that has not changed since its last
 *    backup does not get a new version, only another reference to the old one.
 * 3. Modules are then restored one at a time. Each restore stages and swaps on
 *    its own, so a failure inside one leaves that module untouched; a failure
 *    between two is undone by putting the modules already restored back to
 *    where the safety snapshot found them.
 *
 * What it is not is one filesystem transaction - there is no such thing across
 * N directory trees. Step 3 is compensation, and the exception raised when it
 * runs names the safety snapshot so the way back is never something you have
 * to work out afterwards.
 */
class SnapshotManager
{
    public function __construct(
        private readonly ModuleLocator $locator,
        private readonly BackupRepository $backups,
        private readonly BackupManager $manager,
        private readonly SnapshotRepository $snapshots,
        private readonly ModuleDependencies $dependencies,
    ) {}

    /**
     * Record the version every module in scope is at, backing up what changed.
     *
     * @param  ?string  $root  a module and everything it needs; null for the whole project
     * @return array{snapshot: string, root: ?string, modules: array<string, string>, created: list<string>}
     *
     * @throws ModsxException
     */
    public function take(?string $root = null, ?string $comment = null): array
    {
        $scope = $root === null
            ? $this->locator->names()
            : $this->dependencies->closure($root);

        if ($scope === []) {
            throw ModsxException::noModules();
        }

        $modules = [];
        $created = [];
        $graph = [];

        foreach ($scope as $module) {
            // skipUnchanged is what makes a snapshot cheap enough to take
            // often: an unchanged project writes no version directories at all,
            // and every module still gets a version number to be recorded at.
            $result = $this->manager->backup($module, $comment, skipUnchanged: true);

            $modules[$module] = $result['version'];

            if ($result['skipped'] === false) {
                $created[] = $module;
            }

            $graph[$module] = array_column($this->dependencies->for($module), 'module');
        }

        ksort($modules);

        $id = $this->snapshots->nextId();

        $this->snapshots->write($id, $modules, $graph, $root === null ? null : ModuleName::make($root)->studly, $comment);

        return [
            'snapshot' => $id,
            'root' => $root === null ? null : ModuleName::make($root)->studly,
            'modules' => $modules,
            'created' => $created,
        ];
    }

    /**
     * Put every module in a snapshot back to the version it names.
     *
     * @return array{snapshot: string, safety: string, restored: list<string>}
     *
     * @throws ModsxException
     */
    public function rollback(string $id): array
    {
        $snapshot = $this->snapshots->read($id);

        if ($snapshot === null) {
            throw ModsxException::snapshotNotFound($id);
        }

        $missing = [];

        foreach ($snapshot['modules'] as $module => $version) {
            if (! $this->backups->has($module, $version)) {
                $missing[] = ['module' => $module, 'version' => $version];
            }
        }

        if ($missing !== []) {
            throw ModsxException::snapshotIncomplete($id, $missing);
        }

        $safety = $this->take(null, sprintf('before rolling back to snapshot %s', $id));

        $applied = [];

        try {
            foreach ($snapshot['modules'] as $module => $version) {
                $this->manager->restore($module, $version);
                $applied[] = $module;
            }
        } catch (Throwable $exception) {
            $this->undo($applied, $safety['modules']);

            throw ModsxException::rollbackReverted($id, $safety['snapshot'], $exception->getMessage());
        }

        return [
            'snapshot' => $id,
            'safety' => $safety['snapshot'],
            'restored' => array_keys($snapshot['modules']),
        ];
    }

    /**
     * Remove all but the newest $keep snapshots.
     *
     * Snapshots hold versions back from modsx:prune, so without a way to let
     * one go the backup tree could only ever grow. Removing a snapshot removes
     * no versions - it only stops them being held.
     *
     * @return list<string> snapshots removed, or that would be removed
     *
     * @throws ModsxException
     */
    public function prune(int $keep, bool $dryRun = false): array
    {
        $ids = $this->snapshots->ids();
        $keep = max(1, $keep);

        if (count($ids) <= $keep) {
            return [];
        }

        $removing = array_slice($ids, 0, count($ids) - $keep);

        if (! $dryRun) {
            foreach ($removing as $id) {
                $this->snapshots->delete($id);
            }
        }

        return $removing;
    }

    /**
     * Put back the modules that had already been restored when one failed.
     *
     * Best effort by design: this runs because something has already gone
     * wrong, and giving up on the rest because one of them also fails would
     * leave more of the application in the wrong state, not less. Whatever
     * cannot be undone is still reachable through the safety snapshot, which
     * is why the caller names it.
     *
     * @param  list<string>  $applied
     * @param  array<string, string>  $safety
     */
    private function undo(array $applied, array $safety): void
    {
        foreach ($applied as $module) {
            if (! isset($safety[$module])) {
                continue;
            }

            try {
                $this->manager->restore($module, $safety[$module]);
            } catch (Throwable) {
                // Deliberately swallowed; see the note above.
            }
        }
    }
}
