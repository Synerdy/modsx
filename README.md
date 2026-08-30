# Modsx — convention-based modules for Laravel

[![Latest Version](https://img.shields.io/packagist/v/synerdy/modsx.svg)](https://packagist.org/packages/synerdy/modsx)
[![Tests](https://github.com/Synerdy/modsx/actions/workflows/tests.yml/badge.svg)](https://github.com/Synerdy/modsx/actions)
[![License](https://img.shields.io/packagist/l/synerdy/modsx.svg)](LICENSE)

Organise a Laravel application into modules using nothing but a directory-naming convention — then back them up, version them and restore them from the command line.

📖 **[Full documentation](https://synerdy.github.io/modsx/)** — this README with sidebar navigation.

**Read this in another language:** [Polski](README.pl.md)

---

## The idea

Most Laravel module packages ask you to restructure your application: a separate source tree, a service provider per module, custom autoloading, their own routing and view namespaces. That is a lot of machinery to adopt, and a lot to unwind if you change your mind.

Modsx takes the opposite approach. **A module is just a set of directories that share a name.** You create them yourself, in the places Laravel already puts things:

```
resources/views/modsx-blog/
app/Http/Controllers/ModsxBlog/
```

That is a module. It works immediately — Laravel resolves those views and controllers exactly as it always has, because nothing about the framework has changed. No provider, no namespace registration, no autoload rules.

This package doesn't create that structure and plays no part in running it. It only **finds** it and manages it: backup, versioning, restore, removal.

Three things follow from this:

- **You can adopt the convention without installing anything.** Start prefixing directories today; install the package the day you actually want backups.
- **You can uninstall it and lose nothing.** Remove the package and your modules keep working — they were never anything but ordinary Laravel directories.
- **It composes with the rest of the ecosystem.** Livewire, Filament, Inertia, Folio — anything that reads from `app/`, `resources/` or `routes/` sees ordinary directories, because that is what they are.

The trade-off is honest: this is not a package manager. It does not resolve dependencies between modules, does not manage Composer requirements, and does not touch your database. See [Limitations](#limitations).

---

## Requirements

| | |
|---|---|
| PHP | 8.3+ |
| Laravel | 12.x, 13.x |

---

## Installation

```bash
composer require synerdy/modsx
```

The service provider is auto-discovered. To change anything, publish the config:

```bash
php artisan vendor:publish --tag=modsx-config
```

Backups are written to `modsx-backups/` in your project root. You almost certainly want them out of version control:

```gitignore
# .gitignore
/modsx-backups
```

Committing them instead is a legitimate choice if you want module versions to travel with the repository — just be aware that a backup is a full directory copy, so the repo will grow with every one.

---

## Naming convention

**This is the one section worth reading carefully.** Everything else follows from it.

A module has a single canonical name in **StudlyCase**:

```
Blog        UserProfile        AdminPanel
```

Every other form is derived from that one name, and Modsx matches **all** of them:

| Where | Form | `Blog` | `UserProfile` |
|---|---|---|---|
| Directories under `resources/`, `public/`, `lang/` | `modsx-` + kebab-case | `modsx-blog` | `modsx-user-profile` |
| PHP namespace directories under `app/`, `database/` | `Modsx` + StudlyCase | `ModsxBlog` | `ModsxUserProfile` |
| Single files — `routes/`, `config/`, `lang/` | `modsx-` + kebab-case | `modsx-blog.php` | `modsx-user-profile.php` |
| Migration filenames, after the timestamp | `modsx_` + snake_case | `modsx_blog_…` | `modsx_user_profile_…` |

The first two are Laravel's own convention, not an invention of this package — the framework maps `App\View\Components\UserProfile` to `<x-user-profile>` using exactly the same StudlyCase ↔ kebab-case conversion. If your directories already follow Laravel's naming, they already follow this one.

> **Every form must come from the same name.**
>
> `modsx-userprofile` and `ModsxUserProfile` are **two different modules**: the first is `Userprofile`, the second is `UserProfile`. Back up `UserProfile` and the `modsx-userprofile` views are silently left behind.
>
> Write the name in StudlyCase first, then convert: `UserProfile` → `user-profile`, never `userprofile`. Or let `php artisan modsx:scaffold UserProfile` and `php artisan modsx:make` write them for you, which is the only way to be sure they agree. If you suspect you've already made this mistake somewhere, `php artisan modsx:doctor` will find it.

The `modsx` prefix itself is configurable, so if you prefer `mod-` or your company's initials, change it once and the same rules apply.

### Which names belong to a module

**One rule: a name identifies a module.** The same way in a directory, in a file, and in a migration. `modsx-blog` is Blog's; `modsx-blog-post` is a different name, so it is a different module.

**Directories** — either form of the name:

| Path | Module | Why |
|---|---|---|
| `app/Models/ModsxBlog/` | Blog | StudlyCase form |
| `resources/views/modsx-blog/` | Blog | kebab-case form |
| `resources/views/modsx-blog-post/` | **BlogPost** | a different name is a different module |
| `resources/views/modsx-blogging/` | Blogging | likewise |

**Single files** — the name up to the first dot, exactly:

| Path | Module | Why |
|---|---|---|
| `config/modsx-blog.php` | Blog | the name, exactly |
| `routes/modsx-blog.php` | Blog | any scanned path |
| `lang/en/modsx-blog.php`, `lang/pl/modsx-blog.php` | Blog | every locale |
| `public/modsx-blog.css`, `resources/js/modsx-blog.js` | Blog | any extension |
| `resources/views/modsx-blog.blade.php` | Blog | cut at the **first** dot, so `.blade.php` works |
| `config/modsx-blog-post.php` | **BlogPost** | not Blog — same as the directory |
| `config/modsx-blog-admin.php` | **BlogAdmin** | names a module; unclaimed if there isn't one |
| `config/blog-modsx.php` | none | the prefix is not at the front |
| `app/Support/ModsxBlog.php` | none | single files use the kebab form only |

Because module names are unique, at most one module can ever match a file — two modules cannot both claim one.

Need more than one file in the same place? Use a directory. `config/modsx-blog/settings.php` is read by Laravel as `config('modsx-blog.settings')`, and the whole directory belongs to Blog.

**Migrations** — the module first, then the ordinary Laravel name:

| Name after the timestamp | Module | Why |
|---|---|---|
| `modsx_blog_create_posts_table` | Blog | |
| `modsx_blog_add_slug_to_posts_table` | Blog | |
| `modsx_blog_post_create_comments_table` | **BlogPost**, or Blog if BlogPost doesn't exist | the longer name wins |
| `modsx_blogging_create_x_table` | Blogging | |
| `create_modsx_blog_posts_table` | none | `modsx:doctor` reports it with the name to use |

A migration is the one thing that cannot be named for its module and nothing else — every migration needs its own name — so this is the only place where a longer module name takes precedence.

**`Blog` and `BlogPost` side by side is a supported layout:**

```
app/Models/ModsxBlog/                                    Blog
app/Models/ModsxBlogPost/                                BlogPost
config/modsx-blog.php                                    Blog
config/modsx-blog-post.php                               BlogPost
..._modsx_blog_create_posts_table.php                    Blog
..._modsx_blog_post_create_comments_table.php            BlogPost
```

`modsx:delete Blog` touches the first column only.

### What belongs to a module

| | Backed up | Restored | Removed by `modsx:delete` |
|---|---|---|---|
| Directories (`ModsxBlog/`, `modsx-blog/`) | yes | yes | yes |
| Files (`routes/modsx-blog.php`, …) | yes | yes | yes |
| Migrations | **archived only** | **no** | **no** |

Migrations are the deliberate exception. Modsx never touches the database, so putting an old migration file back while the schema has moved on would leave your repository and your database disagreeing with nothing to say so. Deleting one while its tables still exist would be worse. So they are copied into every backup for reference — you can always read what the schema used to look like — and otherwise left exactly where they are.

### Example layout

```
app/
├── Http/Controllers/ModsxBlog/
│   ├── PostController.php
│   └── CategoryController.php
├── Livewire/ModsxBlog/
│   └── PostList.php
├── Models/ModsxBlog/
│   └── Post.php
└── Services/ModsxBlog/
    └── PostPublisher.php

resources/
├── views/modsx-blog/
│   ├── index.blade.php
│   └── show.blade.php
├── views/components/modsx-blog/
│   └── post-card.blade.php
├── css/modsx-blog/
│   └── blog.css
└── js/modsx-blog/
    └── editor.js

routes/modsx-blog.php
config/modsx-blog.php
database/migrations/2026_01_01_000000_modsx_blog_posts_table.php
```

None of these is mandatory. A module can be a single view folder.

### Livewire

Livewire 3 and 4 work without special handling, because Livewire discovers components by directory:

```
app/Livewire/ModsxBlog/PostList.php               → <livewire:modsx-blog.post-list />
resources/views/livewire/modsx-blog/post-list.blade.php
```

Livewire 4 single-file components live under `resources/views/components/`, so the same prefix applies:

```
resources/views/components/modsx-blog/post-list.blade.php
```

There is nothing Livewire-specific in this package — it sees ordinary directories, which is precisely why it keeps working across Livewire versions.

---

## Commands

Run any command without arguments and it will prompt you, with a picker for existing names rather than free-text entry.

| Command | Purpose |
|---|---|
| `modsx:make {generator} {Module/Name}` | Run one of Laravel's generators with the module filled in |
| `modsx:scaffold {name}` | Create the directory skeleton for a new module |
| `modsx:list` | Modules currently present in the application |
| `modsx:path {name?}` | Everything belonging to a module |
| `modsx:backup {name?}` | Copy a module to a new numbered version |
| `modsx:backuplist {name?}` | Available backup versions |
| `modsx:export {name?} {version?}` | Pack a backup version into a portable .zip |
| `modsx:import {path}` | Unpack a .zip exported by `modsx:export` |
| `modsx:delete {name?}` | Back up, then remove the module |
| `modsx:restore {name?} {version?}` | Back up the current state, then restore a version |
| `modsx:diff {name?} {version?}` | Compare current state against a backup version |
| `modsx:info {name?}` | Show size, file count, and backup history |
| `modsx:prune {name?}` | Remove old versions, keeping the newest |
| `modsx:doctor` | Check for naming problems and orphaned backups |

### `modsx:make`

Runs one of Laravel's own generators with the module written in for you.

```bash
php artisan modsx:make controller Blog/PostController
php artisan modsx:make view Blog/index
php artisan modsx:make migration Blog/create_posts_table
```

This is `php artisan make:*` with the prefix cut out. The generator is Laravel's, the options are Laravel's, the output is Laravel's — the only thing Modsx does is work out the name:

| You type | It runs |
|---|---|
| `modsx:make controller Blog/PostController` | `make:controller ModsxBlog/PostController` |
| `modsx:make view Blog/index` | `make:view modsx-blog/index` |
| `modsx:make config Blog/settings` | `make:config modsx-blog-settings` |
| `modsx:make migration Blog/create_posts_table` | `make:migration modsx_blog_create_posts_table --create=posts` |

Three forms of one name, a different one per generator, is the part of the convention that is easy to get subtly wrong — and getting it wrong makes two modules that read as one. Which form goes where is a table in `config/modsx.php`:

```php
'generators' => [
    '*'         => '{Studly}/',   // ModsxBlog/PostController
    'view'      => '{kebab}/',    // modsx-blog/index
    'config'    => '{kebab}-',    // modsx-blog-settings
    'migration' => '{snake}_',    // modsx_blog_create_posts_table
],
```

Anything not listed gets `*`, which is right for any PHP class. The generators you can run are whatever your application has registered, not a fixed list, so adding an entry makes one from another package — `make:livewire`, `make:filament-resource` — follow the convention too.

**Options for the generator go after `--`**, and are handed on untouched:

```bash
php artisan modsx:make controller Blog/PostController -- --resource --model=Post
php artisan modsx:make model Blog/Post -- -mfs
```

**Use `/`, not `\`.** Both are accepted, but a POSIX shell removes an unquoted backslash before Modsx ever sees it — `Blog\PostController` arrives as `BlogPostController`. PowerShell escapes with a backtick, so a backslash does survive there. `/` is the form that works in every shell.

`--dry-run` prints the command it would run and stops:

```bash
$ php artisan modsx:make migration Blog/create_posts_table --dry-run
  Would run:
  php artisan make:migration modsx_blog_create_posts_table --create=posts
```

That `--create=posts` is not decoration. Laravel guesses the table from the migration name with `/^create_(\w+)_table$/`, which `modsx_blog_create_posts_table` cannot match with the module in front, so without it every create-migration would come out as an empty stub. Modsx runs the guess against the part you actually wrote and passes the answer on.

If the module doesn't exist yet you are told so and asked once — defaulting to yes, with the closest existing name in case it was a typo. A non-interactive run warns and carries on: creating a file is not destructive, and this is the one command in the package that never is.

One thing it can't fix: `make:model -m` has *Laravel* name the migration, so it comes out as `create_posts_table` with no module prefix and does not belong to the module — it will not be backed up with it. Modsx warns when you do that. Generate the migration separately instead.

### `modsx:scaffold`

Creates the directories for a new module. The convention works perfectly well without this command — you can make the directories by hand and never install anything — but typing both forms yourself is the one way to get it wrong. Here they come from a single name, so they cannot disagree.

```bash
php artisan modsx:scaffold Blog
php artisan modsx:scaffold user-profile   # any case; it is normalised
```

Which directories it creates is up to you, in `config/modsx.php`:

```php
'scaffold' => [
    'app/Http/Controllers/{Studly}',
    'app/Models/{Studly}',
    'resources/views/{kebab}',
],
```

`{Studly}` becomes `ModsxBlog`, `{kebab}` becomes `modsx-blog`. Both come from the one name you typed.

It creates directories and nothing else — no controller stubs, no boilerplate. Generating code would make this a code generator, which is exactly what Modsx is not. It never overwrites anything either: directories that already exist are reported and left alone, so it is safe to re-run.

Note that git does not track empty directories, so a skeleton you never fill in quietly disappears at your next commit. That is the intended behaviour: the directories you actually use will have files in them. Until then it still sits on disk, though — `php artisan modsx:doctor --fix` finds and removes any of a module's directories left empty, so you don't have to hunt for them by hand.

### `modsx:list`

```bash
php artisan modsx:list
php artisan modsx:list --json
```

```
 Module        Directories   Files   Backups   Latest
 Blog          4             2       3         0003
 UserProfile   2             -       -         -
```

A module appears here if **any** of its directories exists.

### `modsx:path`

Shows exactly what Modsx considers part of a module — that is, exactly what a backup would copy. Worth running before your first `modsx:delete`.

```bash
php artisan modsx:path Blog
php artisan modsx:path            # every module
php artisan modsx:path --json
```

Directories, files and migrations are listed separately, with migrations marked as archived so it is clear a restore will not put them back.

### `modsx:backup`

Copies every directory belonging to the module into a new sequential version.

```bash
php artisan modsx:backup Blog
php artisan modsx:backup Blog -m "before switching to repository pattern"
php artisan modsx:backup --all                  # every module at once
php artisan modsx:backup Blog --skip-unchanged  # do nothing if nothing changed
php artisan modsx:backup Blog --json
```

```
modsx-backups/
└── Blog/
    ├── 0001/
    │   ├── modsx.json
    │   ├── app/Http/Controllers/ModsxBlog/     ← restored
    │   ├── routes/modsx-blog.php               ← restored
    │   └── _archive/
    │       └── database/migrations/...         ← kept for reference only
    └── 0002/
        └── ...
```

Version numbers come from the highest existing number, never from whatever the filesystem lists last, and the command refuses to write to a path that already exists. Versions are never overwritten and never reused.

Archived migrations sit in `_archive/`, apart from everything else. That is not a label — restore reads the manifest's list of paths and files and never looks anywhere else, so there is no flag anyone could set wrong.

`-m`/`--comment` attaches an optional free-text note to the version — entirely opt-in, there is no prompt for it. It shows up in `modsx:backuplist` and `modsx:info`.

`--skip-unchanged` compares the module against its newest version file by file and does nothing if they match, so a backup on every deploy doesn't fill the disk with identical copies. A changed migration doesn't count as a change here, since it isn't part of what a restore would put back.

Each version carries a `modsx.json` manifest recording the module name, creation time, the exact source paths and files, the archived migrations, the optional comment, and the PHP, Laravel and package versions in use. Restore reads it, so it puts things back where they came from rather than inferring their location.

The whole copy is assembled in a staging directory and moved into place at the end, so an interrupted backup leaves no half-written version behind.

Backing up two modules whose names differ only in letter case is refused. On Windows and macOS `UserProfile` and `Userprofile` are the same directory, so they would share one version sequence and a restore could hand back the wrong module. The refusal applies on every platform: behaviour that depends on the filesystem is worse than a consistent no.

### `modsx:backuplist`

```bash
php artisan modsx:backuplist                    # every module
php artisan modsx:backuplist Blog
php artisan modsx:backuplist Blog --limit=5     # newest 5
php artisan modsx:backuplist --json
```

```
 Blog
 Version   Created                     Directories   Comment
 0001      2026-08-20T09:14:02+02:00   2             -
 0002      2026-08-21T17:40:55+02:00   2             before switching to repository pattern
```

### `modsx:export`

Packs one backup version into a portable `.zip`, written next to the version directory it came from.

```bash
php artisan modsx:export Blog          # newest version
php artisan modsx:export Blog 0003     # a specific version
php artisan modsx:export               # interactive
```

```
modsx-backups/
└── Blog/
    ├── 0001/
    ├── 0002/
    └── 0002.zip     ← created by modsx:export
```

The zip is a derived, on-demand artifact, not a new version. Versions themselves stay unpacked directories, deliberately: open one in a file explorer or `cd` into it, and you see exactly what belongs to the module, instantly — no extracting, no tooling. `modsx:export` doesn't change that default; it adds a single-file form for the one thing unpacked directories are worse at — moving a version somewhere else. Re-running `modsx:export` on the same version overwrites its zip; there is no "already exists" guard here the way there is for a version itself. Pruning a version removes its zip along with it.

Where the zip ends up is not configurable — moving it anywhere else afterwards is a plain `cp`/`mv`, not something Modsx needs to know about.

### `modsx:import`

Unpacks a `.zip` created by `modsx:export` back into the backup tree, at the module and version its own `modsx.json` names — this is how a module travels between projects as a single file instead of a directory tree.

```bash
php artisan modsx:import path/to/Blog-0002.zip
```

Refuses to import over a version that already exists, for the same reason `modsx:backup` refuses to overwrite one: a version, once written, is never silently replaced. After importing, restore it the normal way: `php artisan modsx:restore Blog 0002`.

### `modsx:delete`

**Backs up first**, and removes nothing unless the backup succeeded.

```bash
php artisan modsx:delete Blog
php artisan modsx:delete Blog --force          # skip the prompt, for CI
php artisan modsx:delete Blog --skip-backup    # if you really mean it
php artisan modsx:delete Blog --force --json
```

Everything that will be removed is listed before the confirmation prompt, and the version number created by the backup is printed, so you always know what to pass to `modsx:restore`.

Migrations are listed too — as **kept**. They stay in the application, because their tables are still in your database and deleting the file that documents them would leave the schema with nothing explaining it. `modsx:doctor` will later remind you they belong to a module that is gone.

### `modsx:restore`

```bash
php artisan modsx:restore Blog          # newest version
php artisan modsx:restore Blog 0003     # a specific version
php artisan modsx:restore               # interactive
php artisan modsx:restore Blog --json
```

The sequence is:

1. Back up the module's current state, so the restore is itself reversible.
2. Copy the chosen version out of the backup into a staging area.
3. Move the entire current state aside, in one pass.
4. Move the restored state into place.

Everything is copied out of the backup **before** the application is touched, so a corrupt or incomplete backup is discovered while the current state is still intact. And because step 3 moves the old state aside whole rather than deleting it path by path, a failure during step 4 is undone: you get back exactly what you had, not a half-restored mixture.

Anything the version did not contain is gone afterwards — moved aside and never put back. That is what restoring an exact state has to mean, and `modsx:diff` will tell you in advance what it covers.

Archived migrations are never restored. They are not read at this step at all.

If the module isn't currently in the application, steps 1 and 3 are skipped and this becomes an **install from backup** — which is how you move a module between projects: copy `modsx-backups/Blog/` across and restore it.

### `modsx:prune`

```bash
php artisan modsx:prune                          # every module, config default
php artisan modsx:prune Blog --keep=5
php artisan modsx:prune --keep=3 --dry-run       # show the plan, change nothing
php artisan modsx:prune --dry-run --json         # machine-readable plan, for CI
```

Lists exactly which versions would go, then asks. The newest version is never removed, whatever `--keep` is set to.

### `modsx:diff`

```bash
php artisan modsx:diff Blog          # against the newest version
php artisan modsx:diff Blog 0003     # against a specific version
php artisan modsx:diff               # interactive
php artisan modsx:diff Blog --json
```

Compares the module in your application against a backup version, **file by file**, using a content hash:

- **Added** — in the application now, not in that version. A restore would delete these.
- **Modified** — in both, but the contents differ. A restore would overwrite these.
- **Removed** — in that version, gone from the application. A restore would bring these back.
- **Unchanged** — identical on both sides.

The comparison is on file contents, not directory names, so a module whose files were all rewritten in place is reported as modified rather than unchanged.

```bash
php artisan modsx:diff Blog --summary   # counts only, no file list
```

Worth running before `modsx:restore`: it tells you exactly what you are about to lose.

### `modsx:info`

```bash
php artisan modsx:info Blog
php artisan modsx:info --json
```

Shows:

- **Current state**: whether the module exists in the application, its directories and files, and its total size on disk
- **Backup history**: number of backed-up versions, total backup size, and a table of each version with its creation date, size, archived-migration count, and comment (if one was given at backup time)

Useful for understanding storage usage and deciding whether to prune old versions.

### `modsx:doctor`

```bash
php artisan modsx:doctor
php artisan modsx:doctor --json    # exit code 1 if problems were found, for CI
php artisan modsx:doctor --fix     # remove empty module directories
```

Problems (exit code 1):

- **Module names that differ only in word boundaries**, such as `Userprofile` alongside `UserProfile`. Both are valid names, so nothing else flags this — but it is almost always one module that was meant to be one.
- **Backup trees that differ only in letter case.** On Windows and macOS those are one directory, so two modules share a version sequence and a restore can return the wrong one.
- **Backup versions with no readable `modsx.json`.**

Informational (exit code 0):

- **Migrations that name a module but aren't archived with it** — the classic `create_modsx_blog_posts_table` — together with the name they need instead. Without this the convention would just quietly do nothing and you would never find out why.
- Backups taken while a different prefix was configured.
- Directories in the backup tree that aren't versions, and are therefore skipped when listing.
- Modules present in only one of the two directory forms.
- Backups with no matching module in the application.
- **Empty module directories** — left by `modsx:scaffold`, or by deleting the last file in one by hand. `--fix` removes them. The check looks for files including hidden ones, so a directory kept alive on purpose with a `.gitkeep` is never touched — only a directory with nothing in it at all, at any depth, is reported.
- **Files naming a module that doesn't exist**, such as `config/modsx-blog-admin.php` with no `BlogAdmin` module. The file keeps working; it just belongs to nothing and is backed up with nothing, which is worth knowing.
- **One module's name continuing another's**, such as `BlogPost` alongside `Blog`. A supported layout, listed so the rule for their migrations is stated somewhere: the longer name wins.

---

## Configuration

`config/modsx.php`:

```php
return [

    // Directory prefix. 'modsx' matches modsx-blog and ModsxBlog.
    'prefix' => env('MODSX_PREFIX', 'modsx'),

    // Where versioned backups are written.
    'backup_path' => env('MODSX_BACKUP_PATH', base_path('modsx-backups')),

    // Only these paths are scanned. Keeping the list tight is what keeps
    // discovery fast: a full scan of the project root would walk storage/,
    // .git/ and public/build/.
    'scan_paths' => [
        'app', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'tests',
    ],

    // Directory names never descended into.
    'exclude' => [
        'vendor', 'node_modules', 'storage', 'bootstrap/cache', '.git', '.idea', '.vscode',
    ],

    // What modsx:scaffold creates. Both placeholders come from the one name
    // you type, which is what stops the two forms from drifting apart.
    'scaffold' => [
        'app/Http/Controllers/{Studly}',
        'app/Models/{Studly}',
        'resources/views/{kebab}',
    ],

    // How modsx:make writes the module into the name it hands to Laravel's
    // own generator. '*' is the rule for anything not listed.
    'generators' => [
        '*' => '{Studly}/',
        'view' => '{kebab}/',
        'config' => '{kebab}-',
        'migration' => '{snake}_',
    ],

    // 4 gives 0001, 0002, ...
    'version_padding' => 4,

    // Default for modsx:prune.
    'prune' => ['keep' => 5],

];
```

Two notes:

- If you change `prefix` after creating modules, rename the existing directories to match. Nothing is found under the old prefix.
- The backup directory is never scanned for modules, wherever you point it — including inside a path that is otherwise scanned.

---

## Limitations

Deliberate, and worth knowing before you rely on this:

- **No database, and therefore no migration restore.** Restoring an older version does not roll back migrations or touch data. Migration *files* are archived into every backup so you can read what a schema used to be, but they are never restored and never deleted — putting an old one back while the schema has moved on would leave your repository and your database disagreeing, with nothing to say so. This is a decision, not a gap to be filled later.
- **One module owns its prefix entirely.** `Blog` and `BlogPost` cannot coexist, because `modsx_blog_post_*` reads as belonging to either. `modsx:doctor` reports the conflict rather than guessing.
- **No dependency resolution.** Modsx doesn't know that `Blog` needs `Users`. Restoring one won't restore the other.
- **No Composer integration.** Third-party packages a module depends on remain your `composer.json`'s problem.
- **Backups are plain directory copies.** No compression, no deduplication. A large module backed up fifty times occupies fifty copies — hence `modsx:prune` and `--skip-unchanged`.
- **Restore is recoverable, not atomic.** The current state is moved aside whole before the restored state goes in, so a failure partway through is rolled back automatically. A machine that dies at exactly the wrong moment can still leave the module in pieces — but everything it had is in one place, and the pre-restore backup is still there.

---

## FAQ

**Do I need this package to use the convention?**
No. That's the point. Prefix your directories and everything works. Install the package when you want backups. `modsx:scaffold` and `modsx:make` are conveniences for people who already have it installed, not requirements — they write directories and names you could just as well type by hand.

**Why aren't my migrations being archived?**
Almost certainly the name. The convention is that the name *after the timestamp* starts with the module prefix: `2026_01_01_000000_modsx_blog_create_posts_table.php`, not the usual verb-first `..._create_modsx_blog_posts_table.php`. Run `php artisan modsx:doctor` — it finds migrations that mention a module but aren't named for it, and tells you what to rename them to. `php artisan modsx:make migration Blog/create_posts_table` writes the name correctly in the first place.

**What happens to my modules if I uninstall it?**
Nothing. They are ordinary Laravel directories and were never anything else. Only `modsx-backups/` becomes unmanaged, and that is just files you can keep or delete.

**Does it conflict with `nwidart/laravel-modules`?**
The two solve the same problem in incompatible ways, so running both is a bad idea. They won't clash on disk, though: the default backup directory is `modsx-backups/` precisely to stay clear of that package's `Modules/` source tree.

**Can I move a module to another project?**
Yes, two ways. Copy `modsx-backups/Blog/` into the target project's backup directory and run `php artisan modsx:restore Blog`. Or, for a single file instead of a directory tree: `modsx:export` it, copy the `.zip` across, `modsx:import` it there, then restore. Namespaces survive because the directory layout does.

**Why numbered versions instead of timestamps?**
They're short, they sort correctly, and they're easy to pick at a prompt. The creation time is in the manifest.

**Can two modules share a directory?**
No. A directory belongs to exactly one module — the one its name encodes.

**Is it safe to run in production?**
The commands are developer tools. They confirm before destroying anything and refuse to run non-interactively without `--force`, but a deploy pipeline is not where module directories should be moving around.

---

## Roadmap

Nothing outstanding. Migration restore is deliberately absent rather than
pending — see [Limitations](#limitations).

---

## Contributing

Issues and pull requests welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

```bash
composer install
composer test
composer lint
```

## License

MIT. See [LICENSE](LICENSE).
