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

Two directory forms are derived from that name, and Modsx matches **both**:

| Where | Form | `Blog` | `UserProfile` |
|---|---|---|---|
| Directories under `resources/`, `public/`, `lang/` | `modsx-` + kebab-case | `modsx-blog` | `modsx-user-profile` |
| PHP namespace directories under `app/`, `database/` | `Modsx` + StudlyCase | `ModsxBlog` | `ModsxUserProfile` |

This is Laravel's own convention, not an invention of this package — the framework maps `App\View\Components\UserProfile` to `<x-user-profile>` using exactly the same StudlyCase ↔ kebab-case conversion. If your directories already follow Laravel's naming, they already follow this one.

> **Both forms must come from the same name.**
>
> `modsx-userprofile` and `ModsxUserProfile` are **two different modules**: the first is `Userprofile`, the second is `UserProfile`. Back up `UserProfile` and the `modsx-userprofile` views are silently left behind.
>
> Write the name in StudlyCase first, then convert: `UserProfile` → `user-profile`, never `userprofile`. If you suspect you've already made this mistake somewhere, `php artisan modsx:doctor` will find it.

The `modsx` prefix itself is configurable, so if you prefer `mod-` or your company's initials, change it once and the same rules apply.

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
```

None of these directories is mandatory. A module can be a single view folder.

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
| `modsx:list` | Modules currently present in the application |
| `modsx:path {name?}` | Directories belonging to a module |
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

### `modsx:list`

```bash
php artisan modsx:list
php artisan modsx:list --json
```

```
 Module        Directories   Backups   Latest
 Blog          4             3         0003
 UserProfile   2             -         -
```

A module appears here if **any** of its directories exists.

### `modsx:path`

Shows exactly which directories Modsx considers part of a module — that is, exactly what a backup would copy. Worth running before your first `modsx:delete`.

```bash
php artisan modsx:path Blog
php artisan modsx:path            # every module
php artisan modsx:path --json
```

### `modsx:backup`

Copies every directory belonging to the module into a new sequential version.

```bash
php artisan modsx:backup Blog
php artisan modsx:backup Blog -m "before switching to repository pattern"
```

```
modsx-backups/
└── Blog/
    ├── 0001/
    │   ├── modsx.json
    │   ├── app/Http/Controllers/ModsxBlog/
    │   └── resources/views/modsx-blog/
    └── 0002/
        └── ...
```

Version numbers come from the highest existing number, never from whatever the filesystem lists last, and the command refuses to write to a path that already exists. Versions are never overwritten and never reused.

`-m`/`--comment` attaches an optional free-text note to the version — entirely opt-in, there is no prompt for it. It shows up in `modsx:backuplist` and `modsx:info`.

Each version carries a `modsx.json` manifest recording the module name, creation time, the exact source paths, the optional comment, and the PHP, Laravel and package versions in use. Restore reads it, so it puts directories back where they came from rather than inferring their location.

The whole copy is assembled in a staging directory and moved into place at the end, so an interrupted backup leaves no half-written version behind.

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
```

The directories that will be removed are listed before the confirmation prompt, and the version number created by the backup is printed, so you always know what to pass to `modsx:restore`.

### `modsx:restore`

```bash
php artisan modsx:restore Blog          # newest version
php artisan modsx:restore Blog 0003     # a specific version
php artisan modsx:restore               # interactive
```

The sequence is:

1. Back up the module's current state, so the restore is itself reversible.
2. Remove the current directories.
3. Copy the chosen version back into the application.

Everything is copied out of the backup **before** the application is touched, so a corrupt or incomplete backup is discovered while the current state is still intact.

If the module isn't currently in the application, steps 1 and 2 are skipped and this becomes an **install from backup** — which is how you move a module between projects: copy `modsx-backups/Blog/` across and restore it.

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

- **Current state**: whether the module exists in the application, how many directories and files it has, and its total size on disk
- **Backup history**: number of backed-up versions, total backup size, and a table of each version with its creation date, size, and comment (if one was given at backup time)

Useful for understanding storage usage and deciding whether to prune old versions.

### `modsx:doctor`

```bash
php artisan modsx:doctor
php artisan modsx:doctor --json    # exit code 1 if problems were found, for CI
```

Reports:

- **Modules whose names differ only in word boundaries**, such as `Userprofile` alongside `UserProfile`. Both are valid names, so nothing else flags this — but it is almost always one module that was meant to be one module, and it will be backed up as two.
- Modules present in only one of the two directory forms (informational).
- Backups with no matching module in the application (informational).

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

- **Directories only.** Single files belonging to a module — `routes/modsx-blog.php`, `config/modsx-blog.php`, migration files, `lang/en/modsx-blog.php` — are **not** backed up, deleted or restored. Keep module code in directories, or handle those files yourself.
- **No database.** Restoring an older version does not roll back migrations or touch data. If a version change implies a schema change, that part is on you.
- **No dependency resolution.** Modsx doesn't know that `Blog` needs `Users`. Restoring one won't restore the other.
- **No Composer integration.** Third-party packages a module depends on remain your `composer.json`'s problem.
- **Backups are plain directory copies.** No compression, no deduplication. A large module backed up fifty times occupies fifty copies — hence `modsx:prune`.
- **Restore is not fully atomic.** Each directory is moved into place individually. The window is small and the pre-restore backup is your recovery path, but a machine that dies mid-restore can leave some directories updated and others not.

---

## FAQ

**Do I need this package to use the convention?**
No. That's the point. Prefix your directories and everything works. Install the package when you want backups.

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

- [ ] Optional backup of module-related single files

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
