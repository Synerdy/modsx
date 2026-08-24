# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.1] - 2026-08-25

### Changed

- Console commands renamed from `modules:*` to `modsx:*` (e.g. `modules:list` →
  `modsx:list`). The old `modules:` namespace is generic enough to collide with
  other packages — notably `nwidart/laravel-modules`, which registers its own
  commands under `module:*` — and was the one place in the package that didn't
  carry the `modsx` name already used by the config file, the env variables and
  the directory prefix itself.

## [0.2.0] - 2026-08-24

### Added

- `modsx:diff` — compare a module against a backup version file by file, using
  a content hash, and report added, modified, removed and unchanged files.
  `--summary` prints counts only.
- `modsx:info` — directories, file counts, sizes and backup history for a
  module, including modules that exist only as backups.
- `BackupManager::pathsInBackup()` is now public, so commands can inspect the
  contents of a backup without reaching into the backup tree themselves.
- `--json` on `modsx:prune` (including `--dry-run`) and `modsx:doctor`, so
  both can be scripted from CI instead of parsed from human-readable output.
- PHPStan/Larastan (level 6) added to the dev dependencies and to CI, run via
  `composer analyse`.
- Test coverage for every console command (`modsx:backup`, `modsx:delete`,
  `modsx:restore`, `modsx:prune`, `modsx:list`, `modsx:path`,
  `modsx:doctor`, `modsx:backuplist`); previously only `modsx:diff` and
  `modsx:info` had command-level tests.

### Fixed

- `modsx:restore` no longer stages files under `storage/`. The final step of a
  restore is a rename, and rename fails across filesystems, so staging in
  `storage/` broke restores wherever that directory is a mounted volume.
- A failed move during `modsx:restore` now raises an error naming the backup
  that still holds the module. Previously the return value was discarded, so a
  failure could leave the module deleted and still report success.
- `confirmDestructive()` no longer assumes the calling command defines
  `--force`, which would raise on any command that does not.
- `ModuleLocator` no longer follows symlinks while scanning for module
  directories. Symfony Finder's `followLinks()` takes no arguments, so the
  previous `->followLinks(false)` silently discarded that argument and
  switched symlink-following **on** instead of off, letting discovery (and
  therefore backup/delete/restore) walk outside the project root through a
  linked directory. Found by adding static analysis.

## [0.1.0] - 2026-08-23

First public release. Rewritten from an in-application prototype into an
installable package.

### Added

- `modsx:list`, `modsx:path`, `modsx:backup`, `modsx:backuplist`,
  `modsx:delete`, `modsx:restore`, `modsx:prune`, `modsx:doctor`.
- Publishable configuration (`config/modsx.php`): prefix, backup location,
  scanned paths, exclusions, version width, prune defaults.
- A `modsx.json` manifest in every backup version, recording the module name,
  creation time, source paths and the PHP/Laravel/package versions used.
- Interactive pickers for module and version selection, so names never have to
  be typed by hand.
- `--json` output on the listing commands.
- `--dry-run` on `modsx:prune` and confirmation prompts on every destructive
  command, with `--force` for non-interactive use.

### Fixed

Relative to the pre-release prototype:

- Version numbers are derived from the highest existing number rather than the
  last directory the filesystem happened to return. On filesystems that do not
  return sorted entries, or after a version had been deleted by hand, the old
  behaviour could compute a number that was already in use and merge a new
  backup into it.
- Commands now return conventional exit codes. Previously a successful backup
  returned `1` and a failed one returned `0`, which inverted the meaning of
  every command in a shell or CI pipeline.
- Listing backups no longer throws when the backup directory does not exist.
- Module names are validated, so a name can no longer contain path separators.
- Directory names that match the prefix but cannot form a valid module name are
  skipped instead of raising a type error.
- Configuration is read through `config()` rather than `env()`, so the package
  keeps working after `php artisan config:cache`.
- Backups and restores are assembled in a staging directory and moved into
  place, so an interrupted run no longer leaves a half-written result.

### Changed

- Default backup directory is `ModulesX/`, chosen to avoid colliding with the
  `Modules/` source directory used by `nwidart/laravel-modules`.
- Default prefix is `modsx`.
- Environment variables are `MODSX_PREFIX` and `MODSX_BACKUP_PATH`.
- `modsx:backuplist` takes `--limit` as an option rather than a positional
  argument.
- Scanning is limited to configured paths instead of walking the whole project
  root, which skips `storage/`, `.git/` and build output.
