# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.0] - 2026-08-31

### Fixed

- **Deleting a module could remove a neighbouring module's files.** `Blog`
  claimed every file whose name merely started with `modsx-blog`, so
  `modsx:delete Blog` removed `config/modsx-blog-post.php` - BlogPost's - and
  `modsx:backup Blog` archived BlogPost's migrations into Blog's version.
  `modsx:doctor` reported the pair as an error but nothing stopped the command,
  which is the same shape of bug as the case collision fixed in 0.3.0, where
  the answer was to refuse.

  The cause was that one name meant two different things: as a directory,
  `modsx-blog-admin` named the module BlogAdmin, but as a file it was one more
  of Blog's. Names now read the same way everywhere.

### Changed

- **A file's name identifies a module, exactly as a directory's name does.**
  The boundary is the first dot, never a hyphen: `config/modsx-blog.php` and
  `resources/views/modsx-blog.blade.php` are Blog's, `config/modsx-blog-post.php`
  is BlogPost's alone, and `config/modsx-blog-admin.php` names BlogAdmin.
  Since module names are unique, two modules can no longer both claim one file.

  **Breaking:** a file like `config/modsx-blog-admin.php` was Blog's and now is
  not. `modsx:doctor` lists every such file under a new `unclaimed_files` key so
  nothing goes missing quietly. Where a module genuinely needs several files in
  one place, the form is a directory - `config/modsx-blog/settings.php`, which
  Laravel reads as `config('modsx-blog.settings')`.

  Existing backups are unaffected: `restore` reads the manifest, so a version
  gives back exactly what it recorded.
- **A migration goes to the longest module name that claims it.**
  `modsx_blog_post_create_comments_table` belongs to BlogPost when that module
  exists, and to Blog when it does not. A migration is the one thing that
  cannot be named for its module and nothing else, so this is the only place a
  longer name takes precedence. It is decided by the module list, with no
  vocabulary of migration verbs: `make:migration` accepts any name at all
  (`backfill_`, `cleanup_`), and module names are themselves sometimes verbs
  (`Import`, `Update`).
- **`Blog` alongside `BlogPost` is now a supported layout.** `modsx:doctor` no
  longer counts it as a problem; it is listed informationally, stating how
  their migrations divide.
- `composer smoke` runs the commands by hand in a Testbench application,
  rebuilding package discovery first. The test suite registers the service
  provider itself, so running it writes a package manifest that does not
  mention this package - after which `vendor/bin/testbench` cannot see the
  commands at all. Development only; nothing here affects the package.
- `confirmDestructive()` moved out of `InteractsWithModules`, which every
  command uses, into a `ConfirmsDestructiveActions` trait used only by the
  three that declare `--force`. It had guarded itself with
  `hasOption('force')` precisely because the other eleven commands did not
  have it, which static analysis reported as eleven errors once a stale
  PHPStan cache stopped hiding them. Putting the method where the option
  exists removes the reason for the guard rather than silencing the report.

## [0.4.1] - 2026-08-29

### Added

- **`modsx:doctor --fix`** finds and removes a module's empty directories —
  left behind by `modsx:scaffold` when a module never got, say, any views, or
  by deleting the last file in one by hand. Without `--fix` they are only
  reported, under the new `empty_directories` key. This is informational, not
  a problem: it does not affect the exit code, so existing `modsx:doctor
  --json` use in CI is unaffected.
- The check looks for files including hidden ones
  (`File::allFiles($path, hidden: true)`), so a directory kept alive on
  purpose with a `.gitkeep` is never touched. `File::allFiles()` ignores
  dotfiles by default, which would otherwise have reported such a directory
  as empty and deleted the marker along with it.

## [0.4.0] - 2026-08-27

### Changed

- **`modsx:make` now means something else.** It was the command that created a
  module's directory skeleton; that command is now **`modsx:scaffold`**, and
  `modsx:make` is a wrapper around Laravel's own generators (below). In Laravel
  `make:` means "generate a thing", so `modsx:make Blog` read as "generate
  Blog" to anyone who knows artisan, which is not what it did. Running the old
  syntax gives a message naming the new command rather than a parse error. The
  config key (`modsx.scaffold`) and the class behind it are unchanged - they
  were already named for what they do; it was the command that did not match.

### Added

- **`modsx:make {generator} {Module/Name}`** runs one of Laravel's generators
  with the module written into the name, in the form that generator expects:

  ```
  modsx:make controller Blog/PostController  ->  make:controller ModsxBlog/PostController
  modsx:make view       Blog/index           ->  make:view       modsx-blog/index
  modsx:make migration  Blog/create_posts_table
                        ->  make:migration modsx_blog_create_posts_table --create=posts
  ```

  Three forms of one name, a different one per generator, is the part of the
  convention that is easy to get subtly wrong - and getting it wrong makes two
  modules that read as one, which is the mistake `modsx:doctor` exists to find
  after the fact. Which form goes where is a table in the config
  (`modsx.generators`), so a generator from another package (`make:livewire`,
  `make:filament-resource`) can follow the convention too. The generators on
  offer are whatever the application has registered, not a fixed list.

  Options for the generator are written after `--` and passed on untouched.
  `--dry-run` prints the command instead of running it. Exit code, output and
  behaviour are the wrapped generator's.
- **`--create` / `--table` are worked out for migrations.** Laravel guesses the
  table from the migration name with `/^create_(\w+)_table$/`, which
  `modsx_blog_create_posts_table` cannot match with the module in front - so a
  create-migration named by the convention silently came out as an empty stub
  instead of a `Schema::create`. (Change migrations happened to survive, their
  pattern being anchored at the end.) The guess now runs against the part the
  user actually wrote, and an explicit `--create`/`--table` still wins.
- `modsx:make` warns when `make:model -m` is used: Laravel names that migration
  itself, so it carries no module prefix, does not belong to the module and
  will not be backed up with it.
- New config key `modsx.generators`, mapping a generator to the form of the
  module name it takes (`{Studly}` / `{kebab}` / `{snake}`), with `*` as the
  default. Unlisted generators get `*`, which is right for any PHP class.

## [0.3.0] - 2026-08-27

### Added

- `modsx:make {name}` creates a new module's directory skeleton. Which
  directories it makes is configurable (`modsx.scaffold`, with `{Studly}` and
  `{kebab}` placeholders). It creates directories only - no stubs, no code -
  and skips ones that already exist. Its real value is that both directory
  forms come from a single name, which is the one mistake `modsx:doctor`
  exists to catch after the fact.
- **Single files belonging to a module** - `routes/modsx-blog.php`,
  `config/modsx-blog.php`, `lang/en/modsx-blog.php` - are now backed up,
  restored and deleted with it. They match the same rule as directories: the
  name starts with the module prefix and ends on a word boundary, so
  `modsx-blog-admin.php` is Blog's and `modsx-blogging.php` is not. Recorded
  under a new `files` key in the manifest.
- **Migrations are archived into every backup** under `_archive/`, and are
  never restored and never deleted. The convention is that the filename after
  the timestamp starts with the module's snake form
  (`2026_01_01_000000_modsx_blog_create_posts_table.php`). Recorded under a new
  `archived` manifest key that `restore()` does not read, so nothing can put
  them back by accident. Rationale: this package does not touch the database,
  so returning an old migration file to a schema that has moved on would leave
  the repository and the database disagreeing with nothing to say so.
- `modsx:backup --all` backs up every module in one run.
- `modsx:backup --skip-unchanged` does nothing when the module is identical to
  its newest version, so a backup on every deploy stops filling the disk with
  identical copies. A changed migration does not count, since it is not part of
  what a restore puts back.
- `--json` on `modsx:backup`, `modsx:delete` and `modsx:restore`. They were the
  only commands without it, and the only ones that change anything.
- `modsx:doctor` gained five checks: backup trees differing only in letter
  case, one module sitting inside another's prefix, backup versions with an
  unreadable manifest, migrations that name a module but are not named *for* it
  (with the rename needed), backups taken under a different prefix, and
  directories in the backup tree that are not versions.

### Fixed

- **Two modules whose names differ only in letter case silently shared one
  backup tree.** `UserProfile` and `Userprofile` are separate modules, but on
  Windows and macOS they resolve to the same directory: version numbers
  interleaved, `modsx:restore UserProfile 0002` could return the other module's
  content, and `modsx:prune` could delete its versions. Backing up or importing
  into a colliding tree is now refused - on every platform, because behaviour
  that depends on the filesystem is worse in a backup tool than a consistent
  no. Existing collisions are reported by `modsx:doctor`.
- A restore that failed partway through could leave the module half replaced.
  The current state is now moved aside whole before the restored state goes in,
  and put back if anything fails, so a failure leaves exactly what was there
  before. Filesystem errors during a restore also arrive as a readable message
  instead of an unhandled `ErrorException`.
- `modsx:path` did not show a module's files, while documenting itself as
  showing "exactly what a backup would copy".

### Changed

- **`modsx:list --json` shape.** Was `name => [directories]`; now
  `name => {directories: [], files: []}`.
- **`modsx:path --json` shape.** Was `name => [directories]`; now
  `name => {directories: [], files: [], migrations: []}`.
- **`modsx:info --json`**: `application.files` was a file count and is now the
  list of the module's own files; the count moved to `application.file_count`.
  Each backup version gained an `archived` count.
- `BackupManager::delete()` returns `{paths, files, migrations}` instead of a
  flat list of paths.
- `modsx:list` and `modsx:info` gained columns for files and archived
  migrations; `modsx:delete` now warns which migrations it is leaving behind.
- File-by-file comparison moved out of `DiffCommand` into a `ModuleDiffer`
  class, shared with `--skip-unchanged`.

## [0.2.7] - 2026-08-27

### Added

- Documentation site on GitHub Pages, generated from `README.md`/`README.pl.md`
  with a sidebar table of contents for jumping between sections: English at
  the site root, Polish under `/pl/`. Built by `composer docs`
  (`docs/build.php`, using `league/commonmark` as a `require-dev`-only
  dependency) and committed as static HTML - GitHub Pages serves the files
  as-is, with no build step of its own.

## [0.2.6] - 2026-08-27

### Added

- `-m`/`--comment` on `modsx:backup` attaches an optional free-text note to
  a version, recorded in its `modsx.json` manifest and shown as a column in
  `modsx:backuplist` and `modsx:info`. Purely opt-in - there is no prompt for
  it. `modsx:delete` and `modsx:restore` forward the option to the automatic
  backup they take before acting.
- `modsx:export {name?} {version?}` packs a backup version into a portable
  `.zip`, written next to the version directory it came from. The zip is a
  derived, on-demand artifact, not a new version - versions themselves stay
  unpacked directories, deliberately, so they can be browsed and diffed
  without extracting anything. Pruning a version removes its zip along
  with it.
- `modsx:import {path}` unpacks a `.zip` created by `modsx:export` back into
  the backup tree, at the module and version its own manifest names - this
  is how a module travels between projects as a single file. Refuses to
  import over a version that already exists, same as a normal backup.
- `ext-zip` added to `require` in `composer.json`, needed by the two
  commands above.

## [0.2.5] - 2026-08-25

### Changed

- Command banner logo switched from the typewriter-style figlet rendering to
  a block-character (`█▄▀`) wordmark.

## [0.2.4] - 2026-08-25

### Fixed

- `ModsxServiceProvider::version()` (added in v0.2.3) returned Composer's
  pretty version verbatim, which includes the leading "v" from the git tag
  (e.g. "v0.2.3"). Combined with the literal "v" already in the command
  banner, this printed "vv0.2.3"; in the backup manifest it wrote
  `"modsx_version": "v0.2.3"` instead of a plain semver string. `version()`
  now always strips a leading "v".

## [0.2.3] - 2026-08-25

### Fixed

- The package version reported in the command banner and written to every
  backup manifest (`modsx_version` in `modsx.json`) was a hardcoded constant
  that had drifted out of date at every release since v0.1.0 — v0.2.0 through
  v0.2.2 all reported `0.1.0`. It is now read from Composer's own installed-
  package metadata (`Composer\InstalledVersions`) instead of a value that has
  to be remembered and bumped by hand.

## [0.2.2] - 2026-08-25

### Changed

- Default backup directory renamed from `ModulesX/` to `modsx-backups/`
  (`MODSX_BACKUP_PATH`). `ModulesX` differed from the package's own `Modsx`
  namespace prefix by the case of a single letter, which read as confusingly
  close to a real module directory even though the scanner never treated it as
  one. `modsx-backups` keeps the reason `ModulesX` existed in the first place —
  staying clear of the `Modules/` directory used by `nwidart/laravel-modules` —
  without doubling as a near-miss of the package's own naming convention.

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
