# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0-beta.1] - 2026-09-03

### Added

- **`modsx:snapshot`, `modsx:rollback`, `modsx:snapshotlist`, `modsx:snapshotprune`
  — the whole project at one moment.** A snapshot records which version of each
  module was current together, which answers the question a per-module backup
  cannot: *I cannot restore Blog from three weeks ago, because back then it
  depended on a different User.*

  A snapshot copies nothing. The versions it names are already in the backup
  tree, so what is written is a few hundred bytes of version numbers in
  `modsx-backups/_snapshots/0002.json`; copying them would double the disk cost
  and give one version two places to live. A module unchanged since its last
  backup gets no new version either, only another reference to the one it had,
  so a snapshot of an untouched project writes nothing but the snapshot. That
  is deliberate: a snapshot nobody minds taking is one that will be there when
  it is needed.

  What `modsx:rollback` guarantees, stated exactly, because the difference
  matters. Every version the snapshot names is confirmed to exist before
  anything is touched - that is the failure that actually happens, and it is
  caught while the application is whole. A snapshot of the current state is
  taken first and its number printed at the end. Each module is then staged and
  swapped on its own, and a failure part-way puts the modules already restored
  back where the safety snapshot found them. What it is not is one filesystem
  transaction; there is no such thing across a dozen directory trees, so the
  last step is compensation, and the safety snapshot is named rather than left
  to be worked out.

  It does not touch the database. Rolling code back does not roll a schema
  back, and no migrations are run in either direction.

- **`modsx:deps` — which modules a module needs, worked out by reading it.**
  The graph is derived, not declared. A module called Media appears in other
  modules' code as `ModsxMedia`, `modsx-media` or `modsx_media` and in no other
  form, so a reference to it is something that can be found rather than
  something someone has to remember to write down.

  That is the whole argument against a `requires:` list: it is a register
  nothing checks, and a snapshot built from a stale one is quietly incomplete -
  worse than no snapshot, because it is trusted. A mention in a comment or a
  string counts here too, since the mistake that causes is a snapshot holding
  one module too many, while the opposite mistake breaks a rollback.

  Name boundaries are respected, so `ModsxBlogPost` refers to BlogPost and not
  also to Blog - the same rule that decides which module owns a file. The snake
  form is the exception, where a suffix is ordinary: `modsx_media_assets` is
  Media's table.

  Configuration under `modsx.dependencies` adds edges for what reading cannot
  see - a class name assembled from a string, a listener wired up elsewhere -
  and never replaces the ones found in the code. An edge found in both is
  reported as found, because that is the claim that can be pointed at.

- **`modsx:status`** — every module in one table: what state it is in, which
  version its working tree came from, what the newest backup is, and how many
  files have changed since.

  ```
   Module    State       Current   Latest backup   Changes
   Blog      modified    0001      0002            1
   Shop      clean       0001      0001            0
   Billing   untracked   -         -               -
   Admin     missing     -         0001            -
  ```

  The frame is the one version control already taught everyone: `Current` is
  where the tree came from, so `Changes` counts what has happened since rather
  than the distance to the newest backup, which is a different question and
  gets its own column. That makes a module able to be `clean` and behind at the
  same time, which is the combination worth being told about - backing up from
  an older version builds the next one on top of it, and the listing now says
  so before that happens.

- **A module's working tree records the version it came from**, in
  `modsx-backups/<Module>/modsx-state.json`, written by `modsx:backup` and
  `modsx:restore`. `modsx:import` deliberately records nothing: it adds a
  version to the backup tree without touching the application, so the working
  tree did not come from it, and saying otherwise is the one thing this record
  must never do.

  It never decides whether a module exists. Discovery stays exactly what it
  was, a directory named by the convention, and `ModuleLocator` knows nothing
  about this file: a module made with `mkdir` has no record and is reported
  `untracked`, which is the truthful answer rather than a gap. Delete every one
  of these files and the package behaves as it did before they existed - a
  property with a test on it, because it is what keeps the convention the only
  source of truth about what a module is.

  It lives beside the versions it points at because it means nothing without
  them: when they go, it goes. It cannot live inside the module either, or a
  backup would copy it and version 0004 would contain a file claiming the
  module is at 0003.

- **`modsx:doctor` reports a record naming a version that has been pruned.**
  Informational, not a problem: the record still says truthfully where the tree
  came from, and `modsx:status` carries on by measuring against the newest
  version instead. Deleting the file is a complete fix.


### Changed

- **`modsx:prune` no longer deletes a version that a snapshot names**, the way
  a tag keeps a commit from being collected. Without it a rollback would
  discover the loss at the moment it needed the version, which is too late to
  be useful. It lists what it held back and why, since a command that silently
  removes less than the age rule offered looks like a bug rather than a
  safeguard.

  Deliberately with no override. Everywhere else in this package `--force`
  means *do not ask me*, and letting it also mean *ignore a safeguard* would
  let a scripted prune quietly strand every snapshot naming those versions.
  Releasing them is `modsx:snapshotprune`'s job: let the snapshot go, and the
  versions follow.

- **`modsx:doctor` reports a snapshot naming a version that is no longer
  there.** Informational, and only reachable by editing the backup tree by
  hand, since prune holds those versions back: the snapshot is still listed and
  still looks usable while the one thing it exists for has become impossible.

### Fixed

- **The release workflow no longer asks GitHub to mark a prerelease as the
  latest release.** `v1.0.0-beta.1` is the highest tag in the repository, so
  the newest-tag check claimed the Latest badge for it while the suffix check
  marked it a prerelease - two contradictory instructions in one call. Only
  stable tags are candidates now, which also stops a beta denying the badge to
  the stable release that follows it, since git's version sort puts
  `v1.0.0-beta.1` above `v1.0.0`.

### Documentation

- A `.gitignore` line for anyone who commits their backup tree:
  `modsx-backups/*/modsx-state.json`. Which version *your* working copy came
  from is a local fact, not a shared one, and two people restoring different
  versions would otherwise conflict over it.

## [0.7.0] - 2026-09-02

### Added

- **`modsx:diff` compares two versions with each other.**
  `modsx:diff Blog 0002 0004` leaves the application out of it and answers
  what happened to the module between those two backups. The version named
  first is the baseline in both modes, so `modsx:diff Blog 0002` and
  `modsx:diff Blog 0002 0004` ask the same question from the same starting
  point and only the other side moves; swapping the arguments gives the same
  comparison seen from the other end. The wording follows: between two
  versions no restore is in sight, so the three groups are no longer described
  by what a restore would do to them. The JSON carries `from` and `to` in
  place of `version`, which is how a script tells the two modes apart.

- **`modsx:backuplist` counts files and archived migrations.** A version has
  held both since 0.3.0, so a listing of its directories alone described less
  than half of what was in it.

### Changed

- **A generator's options no longer need `--` in front of them.**
  `modsx:make component blog.alert --view` works, where it used to be
  `modsx:make component blog.alert -- --view` and anything else was refused
  with Symfony's `The "--view" option does not exist` - a message that says
  nothing about the separator you forgot. Which is easy to forget: `--resource`,
  `-m`, `--view` and `--api` come up constantly.

  `modsx:make` answers to one option of its own, `--dry-run`. It stops Symfony
  rejecting what it does not recognise and sorts the raw tokens itself, so
  everything else goes to the generator - which is also why a generator from
  any package needs nothing declared. Writing `--` still works, and is how to
  reach a generator option that collides with ours.

  The cost, stated plainly: a misspelling of `--dry-run` is no longer caught by
  Symfony. It is checked for by name instead, and answered with "Did you mean
  --dry-run?" rather than forwarded to a generator that would blame itself.

- **An exported archive is named after its module: `Blog-0002.zip`.** The old
  name, `0002.zip`, said nothing once the file had been moved or mailed
  anywhere. `modsx:prune` removes both names, so an archive written under the
  old one is still swept along with the version it belongs to rather than left
  behind for good.

- **`modsx:backup` explains itself when a module has files but no directories.**
  It still refuses - a module is a set of directories, and that is what the
  unclaimed-file check rests on - but it now names the files it did find and
  says why they are not enough, instead of reporting the module as missing.

- `public/build` joins the default `exclude` list, so a compiled asset bundle
  is not walked while looking for a module's directories.

### Security

- **A manifest can no longer place files outside the project.** `modsx:import`
  takes the version number and the path list out of a manifest that somebody
  else wrote, and neither was checked. A version of `../../..` and a path of
  `../../escaped` were both reproduced writing outside the project root before
  being fixed. The version is now validated in the one place it becomes a path,
  and a manifest path is refused if it escapes with `..`, is absolute, or
  carries a drive letter. (`ZipArchive::extractTo()` was never the way in - it
  sanitises entry names itself, which was verified rather than assumed.)

### Documentation

- Named a generator from another package concretely, `modsx:make livewire
  Blog/Alert` among them, and corrected the sentence that read as though such
  a generator needed an entry adding. It does not: `*` is already the right
  form for any class, and an entry is for when `*` is *wrong*.

- The migration example carries a verb, `..._modsx_blog_create_posts_table.php`,
  matching what `make:migration` actually produces.

- `modsx:list` says outright that directories are what make a module, while
  files and migrations belong to one without bringing it into being.

- `--force` is documented for `modsx:restore` and `modsx:prune`.

- The configuration block matches the shipped `config/modsx.php` again, `layout`,
  `page` and `partial` included.

- An example built a model with `-mfs`, two lines above the warning against
  exactly that; it now reads `-fs`.

- **An "Upgrading" section**, because a new minor of a `0.x` package does not
  arrive with a plain `composer update`: Composer reads `^0.6.1` as
  `>=0.6.1 <0.7.0`, so the constraint has to be asked for by name. Said once,
  in the place where people look for it, rather than left to be rediscovered.

### Continuous integration

- The workflow rebuilds `docs/` and fails if the result differs from what is
  committed, and checks that the two READMEs stay in step - same headings, same
  number of code blocks, same number of table rows.

## [0.6.0] - 2026-09-01

### Added

- **`modsx:scaffold` takes the directories to create.** Naming them makes those
  instead of the configured list, for the one you want now without changing
  what every future module gets - which until now meant either editing the
  config or typing `modsx-blog` into `mkdir` by hand, the mistake this command
  exists to prevent.

  ```
  modsx:scaffold Blog resources/css               ->  resources/css/modsx-blog/
  modsx:scaffold Blog resources/js app/Services   ->  resources/js/modsx-blog/
                                                      app/Services/ModsxBlog/
  ```

  You write the path as it looks in the project, and the form of the module's
  own directory is read off where that path leads: `app/`, `database/` and
  `tests/` are the PSR-4 roots of a stock Laravel application, where a hyphen
  is not a legal PHP identifier, so those take StudlyCase; everywhere else the
  name is only ever a path. Writing a placeholder settles it yourself for the
  layouts that cannot know about - `modsx:scaffold Blog "modules/Shared/{Studly}"`.

  An existing directory is skipped and reported, as with the configured list,
  and a path may not contain `..`.
- **`modsx:make layout`, `page` and `partial`.** A module's own views are
  `resources/views/modsx-blog/`, but its layout is one slice of the
  application's `layouts/` - the framework's directory first, the module
  second, exactly as in `resources/css/modsx-blog/`. That was the one shape the
  command could not express, since it writes the module at the front of the
  name; the workaround was `make:view layouts.modsx-blog.app`, typing the kebab
  form by hand, which is the mistake this command exists to prevent.

  ```
  modsx:make layout  blog.app    ->  views/layouts/modsx-blog/app.blade.php
  modsx:make page    blog.index  ->  views/pages/modsx-blog/index.blade.php
  modsx:make partial blog.head   ->  views/partials/modsx-blog/head.blade.php
  ```

  There is no `make:layout` in Laravel. These are entries in the same
  `modsx.generators` table, and what makes them different is that the entry
  names the generator to run as well as the form:
  `'layout' => ['view', 'layouts/{kebab}/']`. So the names are yours:
  `'service' => ['class', 'Services/{Studly}/']` gives you `modsx:make service`,
  and it appears in the interactive picker alongside Laravel's own generators.

  Deliberately no `component`: `make:component` is Laravel's own and already
  lands correctly, writing the class and letting Laravel derive
  `views/components/modsx-blog/` from where that class went.

## [0.5.2] - 2026-08-31

### Fixed

- **`modsx:make` rejected the way Laravel writes a view name.** A view is
  `blog.create` in the framework's own documentation - all lower case, dots
  throughout - but the module had to be separated with a slash, and typing the
  natural form got an error about backslashes. The module now ends at the first
  `/`, `\` or `.`, whichever comes first, so `modsx:make view blog.create` and
  `modsx:make view Blog/create` are the same call. Only the first separator
  divides, a module name being unable to contain one, so the rest of the name
  keeps its own dots.

  The output was never wrong: `make:view` does `str_replace(['\\', '.'], '/', $name)`
  itself, which makes `modsx-blog/create` and `modsx-blog.create` the same view.
  It was the input that was too narrow.

### Documentation

- The `modsx:make` section now lists every generator Laravel ships, written as
  the call you would make through Modsx, with the name each one receives. All
  of it was read off real runs rather than reasoned about: 28 generators take
  PascalCase and fall under `*`, three have a form of their own, and the six
  `*-table` generators take no name at all, being framework scaffolds rather
  than anything belonging to a module.
- Named the one place Modsx departs from plain Laravel. A config name is
  snake_case to `make:config`, but `config/modsx_blog_services.php` would not
  be recognised as the module's file - the rule looks for the `modsx-` kebab
  prefix - so the config would be orphaned, backed up with nothing and removed
  with nothing. Kebab-case there is forced by the convention, not chosen.

## [0.5.1] - 2026-08-31

### Documentation

- Installation now reads `composer require --dev synerdy/modsx`. Outside an
  artisan command the package does nothing at all - the service provider
  returns immediately unless the application is running in the console - and
  nothing in an application ever calls into it, so it belongs with the tooling.
  The one case for `require` is named too: running `modsx:*` where dev
  dependencies are absent.
- **Removed a limitation that no longer exists.** *Limitations* still said
  `Blog` and `BlogPost` could not coexist and that `modsx:doctor` reported
  their prefix as a conflict. Both stopped being true in 0.5.0; what remains is
  that a migration matching two modules goes to the longer name, which is what
  that entry now says.

### Added

- The published config now offers a longer `scaffold` list, commented out:
  Livewire, services, form requests, middleware, factories, seeders, tests,
  `resources/css/`, `resources/js/`, components. The defaults are unchanged and
  deliberately short - a directory nobody fills in is invisible to git and
  reported by `modsx:doctor`, so a generous default would only make work for
  `--fix`. Anonymous components are listed as
  `resources/views/components/{kebab}`, which is where Laravel resolves them
  rather than where they read best - and `layouts/`, `partials/` and `pages/`
  take the same shape, the module going inside the framework's directory just
  as it does in `resources/css/`. `layouts/modsx-blog/`, not
  `modsx-blog/layouts/`: the module comes second everywhere else in this
  convention, and inverting it here is what would stop
  `<x-modsx-blog.card>` resolving. A starter kit's own `layouts/app.blade.php`
  carries no prefix, so no module claims it, and the two sit side by side.

### Fixed

- **`modsx:make` converted only half the name.** The module was written in the
  generator's form and the rest of the name was passed through untouched, so
  `modsx:make config Blog/MailSettings` produced `modsx-blog-MailSettings` -
  kebab-case and StudlyCase inside one identifier, which is neither a config
  key you would type nor one Laravel would generate. `modsx:make view
  Blog/PostList` gave `modsx-blog/PostList` for the same reason. Migrations
  were already converted; nothing else was.

  The entry in `modsx.generators` now settles the whole name: `{kebab}` gives
  `modsx-blog/post-list`, `{snake}` gives `modsx_blog_create_posts_table`, and
  `{Studly}` leaves the rest alone, a class name already being written the way
  its generator wants it. Conversion runs segment by segment, because
  `Str::kebab('Admin/PostList')` is `admin/-post-list` - the separator reads as
  a word boundary.

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
