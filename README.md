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

| Requirement | Version |
|---|---|
| PHP | 8.3+ |
| Laravel | 12.x, 13.x |

---

## Installation

```bash
composer require --dev synerdy/modsx
```

`--dev`, because Modsx is tooling: outside an artisan command it does nothing at all — the service provider returns immediately unless the application is running in the console, and no part of your application ever calls into this package. The convention keeps working with Modsx uninstalled; that is the whole point of it.

Install it into `require` instead if you run `modsx:*` where dev dependencies are absent — a deploy script that backs a module up before it changes, or CI that runs `modsx:doctor` after `composer install --no-dev`.

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

### Upgrading

While Modsx is on `0.x`, a new minor does **not** arrive with a plain `composer update`. Ask for it by name:

```bash
composer require --dev synerdy/modsx:^0.7
```

Composer treats anything below `1.0.0` with pre-release caution: there, `^0.6.1` means `>=0.6.1 <0.7.0`, putting the minor where the major normally sits. So `composer update` stays on the minor you installed — by design, not by accident — and `composer why-not synerdy/modsx 0.7.0` will tell you as much. Requiring the new minor rewrites the constraint and updates in one step.

Worth reading the [changelog](https://github.com/Synerdy/modsx/blob/master/CHANGELOG.md) first: on `0.x` a minor is allowed to carry a breaking change, and they are called out there when it does.

#### Trying the 1.0 beta

The commands marked **1.0** in the table below are not in `0.7.0`. They are in a prerelease, which Composer will not install unless you say so:

```bash
composer require --dev synerdy/modsx:1.0.0-beta.1     # this exact beta
```

Naming a version with a suffix lifts the stability filter for that package on its own — there is no need to touch `minimum-stability`. To follow every 1.0 prerelease instead of pinning one, put this in `composer.json` and run `composer update synerdy/modsx`:

```json
"require-dev": {
    "synerdy/modsx": "^1.0@beta"
}
```

A plain `composer require synerdy/modsx` still resolves to the newest **stable** release, so nobody gets a beta by accident. Going back is the same command with a stable constraint:

```bash
composer require --dev synerdy/modsx:^0.7
```

It is a beta because two things it writes — `modsx-state.json` and `_snapshots/*.json` — have never been used outside its own tests. If either turns out to need a different shape, changing it now costs nothing; after `1.0.0` it costs a migration path or a major version. Treat the backup tree it writes as something you may be asked to delete and recreate.

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
| PHP namespace directories under `app/`, `database/`, `tests/` | `Modsx` + StudlyCase | `ModsxBlog` | `ModsxUserProfile` |
| Single files — `routes/`, `config/`, `lang/` | `modsx-` + kebab-case | `modsx-blog.php` | `modsx-user-profile.php` |
| Migration filenames, after the timestamp | `modsx_` + snake_case | `modsx_blog_…` | `modsx_user_profile_…` |

The first two are Laravel's own convention, not an invention of this package — the framework maps `App\View\Components\UserProfile` to `<x-user-profile>` using exactly the same StudlyCase ↔ kebab-case conversion. With the prefix it works the same way: `App\View\Components\ModsxUserProfile\PostCard` is `<x-modsx-user-profile.post-card>`. If your directories already follow Laravel's naming, they already follow this one.

The split between the two forms is not cosmetic. Directories under `app/`, `database/` and `tests/` are PSR-4 namespace segments, and a PHP identifier cannot contain a hyphen — `App\Support\modsx-blog` is not a name PHP will ever load. Everywhere else the name is just a path, so kebab-case applies.

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
| `public/modsx-blog.css`, `public/modsx-blog.min.js` | Blog | any extension, cut at the **first** dot |
| `config/modsx-blog-post.php` | **BlogPost** | not Blog — same as the directory |
| `config/modsx-blog-admin.php` | **BlogAdmin** | names a module; unclaimed if there isn't one |
| `config/blog-modsx.php` | none | the prefix is not at the front |
| `app/Support/ModsxBlog.php` | none | classes live in the module's directory — `app/Support/ModsxBlog/ChangeFormat.php` |

Because module names are unique, at most one module can ever match a file — two modules cannot both claim one.

The single-file form is for the places Laravel expects one file per concern — `routes/`, `config/`, `lang/`. Everywhere else a module is a directory: views in `resources/views/modsx-blog/`, source assets in `resources/css/modsx-blog/` and `resources/js/modsx-blog/`, classes in `app/Support/ModsxBlog/`. That is what `modsx:scaffold` creates, and it is the form to reach for whenever you are unsure — a directory is matched by its exact name and holds as much as you like. Even `config/modsx-blog/settings.php` works, read by Laravel as `config('modsx-blog.settings')`.

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
database/migrations/2026_01_01_000000_modsx_blog_create_posts_table.php
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
| `modsx:scaffold {name} {path?*}` | Create a module's directories, configured or named |
| `modsx:list` | Modules currently present in the application |
| `modsx:status {name?}` | **1.0** &nbsp; Every module: what it is, what it came from, what has moved |
| `modsx:deps {name?}` | **1.0** &nbsp; Which modules a module needs, worked out by reading it |
| `modsx:path {name?}` | Everything belonging to a module |
| `modsx:backup {name?}` | Copy a module to a new numbered version |
| `modsx:backuplist {name?}` | Available backup versions |
| `modsx:export {name?} {version?}` | Pack a backup version into a portable .zip |
| `modsx:import {path}` | Unpack a .zip exported by `modsx:export` |
| `modsx:delete {name?}` | Back up, then remove the module |
| `modsx:restore {name?} {version?}` | Back up the current state, then restore a version |
| `modsx:diff {name?} {version?} {against?}` | Compare against a backup version, or two versions with each other |
| `modsx:info {name?}` | Show size, file count, and backup history |
| `modsx:prune {name?}` | Remove old versions, keeping the newest |
| `modsx:snapshot {name?}` | **1.0** &nbsp; Record the version every module is at, as one snapshot |
| `modsx:snapshotlist` | **1.0** &nbsp; Snapshots that have been taken |
| `modsx:rollback {snapshot?}` | **1.0** &nbsp; Put the whole project back to a snapshot |
| `modsx:snapshotprune` | **1.0** &nbsp; Remove old snapshots, keeping the newest |
| `modsx:doctor` | Check for naming problems and orphaned backups |

Commands marked **1.0** are in the `1.0.0-beta.1` prerelease and not in the current stable release — see [Trying the 1.0 beta](#trying-the-10-beta).

### `modsx:make`

Runs one of Laravel's own generators with the module written in for you.

```bash
php artisan modsx:make controller Blog/PostController
php artisan modsx:make view blog.index
php artisan modsx:make migration Blog/create_posts_table
```

This is `php artisan make:*` with the prefix cut out. The generator is Laravel's, the options are Laravel's, the output is Laravel's — the only thing Modsx does is work out the name:

| You type | It runs |
|---|---|
| `modsx:make controller Blog/PostController` | `make:controller ModsxBlog/PostController` |
| `modsx:make view blog.index` | `make:view modsx-blog/index` |
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

Anything not listed gets `*`, which is right for any PHP class.

Anything not listed gets `*`, and the generators you can run are whatever your application has registered, not a fixed list — a package's own is wrapped just as well as Laravel's.

#### Every Laravel generator, and the name it gets

Laravel ships three naming styles across its generators, and the table above covers all three. Written out in full, against Laravel 12/13:

**PascalCase — into the module's namespace directory.** These all fall under `*`:

| You type | It runs |
|---|---|
| `modsx:make cast Blog/MoneyCast` | `make:cast ModsxBlog/MoneyCast` |
| `modsx:make channel Blog/OrderChannel` | `make:channel ModsxBlog/OrderChannel` |
| `modsx:make class Blog/PaymentService` | `make:class ModsxBlog/PaymentService` |
| `modsx:make command Blog/SendEmails` | `make:command ModsxBlog/SendEmails` |
| `modsx:make component Blog/Alert` | `make:component ModsxBlog/Alert` |
| `modsx:make controller Blog/UserController` | `make:controller ModsxBlog/UserController` |
| `modsx:make enum Blog/OrderStatus` | `make:enum ModsxBlog/OrderStatus` |
| `modsx:make event Blog/OrderCreated` | `make:event ModsxBlog/OrderCreated` |
| `modsx:make exception Blog/PaymentException` | `make:exception ModsxBlog/PaymentException` |
| `modsx:make factory Blog/UserFactory` | `make:factory ModsxBlog/UserFactory` |
| `modsx:make interface Blog/PaymentGateway` | `make:interface ModsxBlog/PaymentGateway` |
| `modsx:make job Blog/ProcessOrder` | `make:job ModsxBlog/ProcessOrder` |
| `modsx:make job-middleware Blog/RateLimited` | `make:job-middleware ModsxBlog/RateLimited` |
| `modsx:make listener Blog/SendWelcomeEmail` | `make:listener ModsxBlog/SendWelcomeEmail` |
| `modsx:make mail Blog/OrderShipped` | `make:mail ModsxBlog/OrderShipped` |
| `modsx:make middleware Blog/Authenticate` | `make:middleware ModsxBlog/Authenticate` |
| `modsx:make model Blog/User` | `make:model ModsxBlog/User` |
| `modsx:make notification Blog/InvoicePaid` | `make:notification ModsxBlog/InvoicePaid` |
| `modsx:make observer Blog/UserObserver` | `make:observer ModsxBlog/UserObserver` |
| `modsx:make policy Blog/UserPolicy` | `make:policy ModsxBlog/UserPolicy` |
| `modsx:make provider Blog/AppServiceProvider` | `make:provider ModsxBlog/AppServiceProvider` |
| `modsx:make request Blog/StoreUserRequest` | `make:request ModsxBlog/StoreUserRequest` |
| `modsx:make resource Blog/UserResource` | `make:resource ModsxBlog/UserResource` |
| `modsx:make rule Blog/ValidPhoneNumber` | `make:rule ModsxBlog/ValidPhoneNumber` |
| `modsx:make scope Blog/PopularScope` | `make:scope ModsxBlog/PopularScope` |
| `modsx:make seeder Blog/UserSeeder` | `make:seeder ModsxBlog/UserSeeder` |
| `modsx:make test Blog/UserTest` | `make:test ModsxBlog/UserTest` |
| `modsx:make trait Blog/HasRoles` | `make:trait ModsxBlog/HasRoles` |

**The three that differ:**

| You type | It runs | Form |
|---|---|---|
| `modsx:make view blog.users.index` | `make:view modsx-blog/users.index` | view path |
| `modsx:make config blog.services` | `make:config modsx-blog-services` | kebab-case |
| `modsx:make migration blog.create_users_table` | `make:migration modsx_blog_create_users_table --create=users` | snake_case |

**Either separator, for every generator.** The tables above pick whichever reads more naturally, but the module ends at the first `/`, `\` or `.` whatever you are generating. These are the same call:

| With a slash | With a dot |
|---|---|
| `modsx:make config Blog/services` | `modsx:make config blog.services` |
| `modsx:make migration Blog/create_users_table` | `modsx:make migration blog.create_users_table` |
| `modsx:make controller Blog/UserController` | `modsx:make controller Blog.UserController` |

`make:config` is the one place Modsx departs from plain Laravel, where a config name is snake_case. It has to: `config/modsx_blog_services.php` would **not** be recognised as the module's file — the rule looks for the `modsx-` kebab prefix — so the config would be orphaned, backed up with nothing and removed with nothing. Kebab-case here is forced by the convention, not chosen.

**Not module-scoped at all:** `make:cache-table`, `make:session-table`, `make:notifications-table`, `make:queue-table`, `make:queue-batches-table` and `make:queue-failed-table` take no name — they generate a fixed framework migration. Pass one through `modsx:make` and Laravel answers *"No arguments expected"*, which is the right answer: those tables belong to the application, not to a module.

**Generators from other packages** are on the same footing — the list is whatever your application has registered, and `*` is already the right form for a class:

| You type | It runs | What you get |
|---|---|---|
| `modsx:make livewire Blog/Alert` | `make:livewire ModsxBlog/Alert` | `app/Livewire/ModsxBlog/Alert.php` and `views/livewire/modsx-blog/alert.blade.php`, so `<livewire:modsx-blog.alert />` |
| `modsx:make filament-resource Blog/PostResource` | `make:filament-resource ModsxBlog/PostResource` | `app/Filament/Resources/ModsxBlog/PostResource.php` |

Livewire derives its view path from where the class went, exactly as Laravel's own components do, so StudlyCase is all it needs from us. Add a `modsx.generators` entry only when `*` is **wrong** for a generator — when its name is a path or a filename rather than a class.

#### `layout`, `page`, `partial`

A module's own views live in `resources/views/modsx-blog/`, but its layout is one slice of the application's `layouts/` — the framework's directory first, the module second, exactly as in `resources/css/modsx-blog/`. Three names of Modsx's own reach those:

```bash
php artisan modsx:make layout blog.app     # -> resources/views/layouts/modsx-blog/app.blade.php
php artisan modsx:make page blog.index     # -> resources/views/pages/modsx-blog/index.blade.php
php artisan modsx:make partial blog.head   # -> resources/views/partials/modsx-blog/head.blade.php
```

There is no `make:layout` in Laravel. These are entries in the same config table, and what makes them different is that the entry names the generator to run as well as the form:

```php
'generators' => [
    'view'    => '{kebab}/',                  // runs make:view
    'layout'  => ['view', 'layouts/{kebab}/'],  // also make:view, elsewhere
    'page'    => ['view', 'pages/{kebab}/'],
    'partial' => ['view', 'partials/{kebab}/'],
],
```

So the names are yours to choose. `'service' => ['class', 'Services/{Studly}/']` gives you `modsx:make service Blog/PostPublisher` writing `app/Services/ModsxBlog/PostPublisher.php`, and it appears in the picker alongside Laravel's own.

There is deliberately no `component`: `make:component` is a generator of Laravel's and already lands correctly — it writes the class, and Laravel derives `views/components/modsx-blog/` from where that class went.

**The form applies to the whole name, not just the module.** Type it however you like and the generator receives it in the form its entry names, converted segment by segment:

```bash
php artisan modsx:make view blog.PostList          # -> modsx-blog/post-list
php artisan modsx:make view blog.admin.PostList    # -> modsx-blog/admin.post-list
php artisan modsx:make config Blog/MailSettings    # -> modsx-blog-mail-settings
php artisan modsx:make migration Blog/CreatePostsTable
                                                   # -> modsx_blog_create_posts_table
php artisan modsx:make controller Blog/PostController
                                                   # -> ModsxBlog/PostController, untouched
```

A `{Studly}` entry leaves the rest of the name alone, since a class name is already written the way the generator wants it.

**The generator's options are written where you would write them anyway,** and handed on untouched:

```bash
php artisan modsx:make controller Blog/PostController --resource --model=Post
php artisan modsx:make model Blog/Post -fs
php artisan modsx:make component blog.alert --view
```

`modsx:make` answers to exactly one option of its own, `--dry-run`. Everything else it does not recognise belongs to the generator and is passed through, which is why nothing needs declaring for `make:livewire` or any other package's.

A `--` still works, and is the way to reach a generator option that collides with ours:

```bash
php artisan modsx:make controller Blog/PostController -- --dry-run
```

**Separate the module with `/` or `.`,** whichever the generator you are calling reads better with. A view name is written with dots in Laravel's own documentation, so write it that way here too — all lower case, exactly as you would type it to `make:view`:

```bash
php artisan modsx:make view blog.create        # -> make:view modsx-blog/create
php artisan modsx:make view blog.admin.index   # -> make:view modsx-blog/admin.index
php artisan modsx:make controller Blog/PostController
```

Only the **first** separator divides — a module name can contain neither — so the rest of the name keeps its own dots. The two forms are interchangeable, and so is the result: `make:view` turns dots into slashes itself, which is why `modsx-blog/create` is the same view as `modsx-blog.create`.

A backslash works too, but avoid it in a POSIX shell: an unquoted one is removed before Modsx ever sees it, so `Blog\PostController` arrives as `BlogPostController`. PowerShell escapes with a backtick, so there it survives.

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

#### The configured list

Which directories it creates is up to you, in `config/modsx.php`:

```php
'scaffold' => [
    'app/Http/Controllers/{Studly}',
    'app/Models/{Studly}',
    'resources/views/{kebab}',
],
```

`{Studly}` becomes `ModsxBlog`, `{kebab}` becomes `modsx-blog`. Both come from the one name you typed.

The published config carries a longer list commented out — Livewire, services, form requests, factories, seeders, tests, `resources/css/`, `resources/js/`, components — so you uncomment what your modules have. The default stays short on purpose: a directory nobody fills in is invisible to git and gets reported by `modsx:doctor`, so a generous default would only make work for `--fix`.

Views follow the same shape as everything else — **the framework's directory first, the module inside it**, exactly like `resources/css/modsx-blog/`:

```
resources/views/
├── components/
│   ├── layouts/app.blade.php     the application's
│   └── modsx-blog/card.blade.php Blog's   -> <x-modsx-blog.card>
├── layouts/
│   ├── app.blade.php             the application's
│   └── modsx-blog/               Blog's
├── partials/modsx-blog/          Blog's
└── modsx-blog/                   Blog's own pages
```

The two live side by side: a starter kit's `layouts/app.blade.php` carries no prefix, so no module ever claims it, while `layouts/modsx-blog/` is Blog's and travels with it. Modules are found at any depth, so nesting one level in costs nothing.

Note the direction. `layouts/modsx-blog/` — not `modsx-blog/layouts/`. The module goes *inside* the framework's directory everywhere else in this convention, and views are no exception; inverting it here is what would make `<x-modsx-blog.card>` stop resolving.

#### Naming the directories yourself

Name directories after the module and it makes those instead of the configured list — for the one you want now, without changing what every future module gets:

```bash
php artisan modsx:scaffold Blog resources/css
# resources/css/modsx-blog/

php artisan modsx:scaffold Blog resources/css resources/js app/Services
# resources/css/modsx-blog/
# resources/js/modsx-blog/
# app/Services/ModsxBlog/
```

Note the third one: **`ModsxBlog`, not `modsx-blog`**. You write the path as it looks in the project and the form of the module's own directory is read off where that path leads:

| You type | It creates | Why |
|---|---|---|
| `resources/css` | `resources/css/modsx-blog` | a path, so kebab-case |
| `resources/js` | `resources/js/modsx-blog` | |
| `resources/views/layouts` | `resources/views/layouts/modsx-blog` | |
| `public/vendor` | `public/vendor/modsx-blog` | |
| `lang/en` | `lang/en/modsx-blog` | |
| `app/Services` | `app/Services/`**`ModsxBlog`** | `app/` is PSR-4 |
| `app/Livewire` | `app/Livewire/`**`ModsxBlog`** | |
| `database/factories` | `database/factories/`**`ModsxBlog`** | `database/` is PSR-4 |
| `tests/Feature` | `tests/Feature/`**`ModsxBlog`** | `tests/` is PSR-4 |

`app/`, `database/` and `tests/` are the PSR-4 roots of a stock Laravel application — `App\`, `Database\`, `Tests\` — and a hyphen is not a legal PHP identifier, so directories under them take the StudlyCase form. Everywhere else the name is only ever a path. This is the convention's own rule, applied for you rather than invented here.

Where that guess is wrong — a PSR-4 root of your own, say — write the placeholder and it settles the form instead:

```bash
php artisan modsx:scaffold Blog "modules/Shared/{Studly}"
# modules/Shared/ModsxBlog/

php artisan modsx:scaffold Blog "storage/exports/{kebab}"
# storage/exports/modsx-blog/
```

A directory that already exists is reported and left alone, exactly as with the configured list, so running it twice is safe:

```bash
$ php artisan modsx:scaffold Blog resources/css
  resources/css/modsx-blog ...................................... created

$ php artisan modsx:scaffold Blog resources/css
  resources/css/modsx-blog ................................ already existed
```

And a path cannot leave the project:

```bash
$ php artisan modsx:scaffold Blog ../../etc
   ERROR  Invalid path [../../etc]. Give a directory inside the project, such as
          "resources/css" or "app/Services"; it may not contain "..".
```

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

A module appears here if **any of its directories** exists — a module is a set of directories, and that is what makes one. Files and migrations named for a module belong to it, and are counted in the columns above, but they do not bring one into being: a `config/modsx-blog.php` with no `modsx-blog` directory anywhere is reported by `modsx:doctor` as naming a module that does not exist, and `modsx:backup Blog` will say the same.

### `modsx:status`

```bash
php artisan modsx:status
php artisan modsx:status Blog
php artisan modsx:status --json
```

```
 Module    State       Current   Latest backup   Changes
 Blog      modified    0001      0002            1
 Shop      clean       0001      0001            0
 Billing   untracked   -         -               -
 Admin     missing     -         0001            -
```

The shape is the one version control already taught you. **Current** is the version the working tree came from, so **Changes** counts what has happened since — not the distance to the newest backup, which is a different question and gets its own column.

| State | Meaning |
|---|---|
| `clean` | In the application, identical to the version it came from |
| `modified` | In the application, differs from it — `Changes` says by how many files |
| `untracked` | In the application, never backed up |
| `missing` | Backups exist, but the module is not in the application |

A module can be `clean` and still be behind, and that combination is the one worth being told about:

```
 WARN  [Blog] is working from 0001, but 0002 exists. Backing up now would build the next version on the older one.
```

#### Where `Current` comes from

`modsx:backup` and `modsx:restore` record the version in `modsx-backups/Blog/modsx-state.json`. `modsx:import` deliberately records nothing: it adds a version to the backup tree without touching the application, so the working tree did not come from it.

This file **never decides whether a module exists**. Discovery stays what it has always been, a directory named by the convention. A module you made with `mkdir` has no record here and is reported `untracked` — the truthful answer rather than a gap. Delete every one of these files and the package behaves exactly as it did before they existed; only the `Current` column goes blank.

It sits beside the versions it points at because it means nothing without them. Prune the version it names and `modsx:doctor` reports it, while `modsx:status` carries on by measuring against the newest version instead.

If you commit your backup tree, keep this file out of it — which version *your* working copy came from is a local fact, not a shared one:

```gitignore
modsx-backups/*/modsx-state.json
```

### `modsx:deps`

```bash
php artisan modsx:deps          # every module
php artisan modsx:deps Blog     # one module, and what a snapshot of it would hold
php artisan modsx:deps --json
```

```
 INFO  Blog
  Media ......................... found in the code
  User .......................... found in the code

 INFO  Media
  needs nothing else
```

The graph is **derived, not declared.** A module called `Media` appears in other modules' code as `ModsxMedia`, `modsx-media` or `modsx_media` and in no other form, so a reference to it is something that can be found rather than something you have to remember to write down.

That is the whole argument for reading it rather than keeping a list. A hand-kept list of requirements is a list nothing checks: add a reference to another module, forget to update the list, and a snapshot built from it is quietly incomplete — which is worse than no snapshot, because it is trusted.

| Where it looks | What counts as a reference |
|---|---|
| `use App\Models\ModsxMedia\Asset;` | the Studly form, anywhere in PHP |
| `@include('modsx-media.player')` | the kebab form, in any file |
| `config('modsx-media.disk')` | the same form, in any call |
| `$table = 'modsx_media_assets';` | the snake form, table names included |

A mention inside a comment or a string counts too. That is deliberate: the mistake it causes is a snapshot holding one module too many, which costs a directory, while the opposite mistake breaks a rollback.

Name boundaries are respected, so `ModsxBlogPost` is a reference to `BlogPost` and not also to `Blog` — the same rule that decides which module owns a file. The one exception is the snake form, where a suffix is ordinary: `modsx_media_assets` is Media's table, so an underscore is allowed to follow it.

Given modules `Blog`, `BlogPost`, `Media` and `MediaAssets`, this is what a line inside `Blog` resolves to:

| Line found in Blog | Edge to |
|---|---|
| `use App\Models\ModsxMedia\Asset;` | `Media` |
| `use App\Models\ModsxMediaAssets\Row;` | `MediaAssets` — not `Media` |
| `@include('modsx-media.player')` | `Media` |
| `@include('modsx-media-assets.row')` | `MediaAssets` — not `Media` |
| `view('modsx-blog-post.comment')` | `BlogPost` — not `Blog` |
| `config('modsx-media.disk')` | `Media` |
| `$table = 'modsx_media_assets';` | `Media` **and** `MediaAssets` — the snake form allows a suffix |
| `// see also ModsxMedia` | `Media` — a comment counts |
| `use App\Models\ModsxBlog\Post;` inside Blog itself | nothing; a module never needs itself |
| `use App\Models\ModsxGhost\Thing;` with no Ghost module | nothing; only real modules can be edges |


#### What reading cannot see

A class name assembled from a string, a listener wired up somewhere else. Those go in configuration, which **adds** edges and never replaces the ones found in the code:

```php
// config/modsx.php
'dependencies' => [
    'Blog' => ['Search'],
],
```

An edge found in both places is reported as *found in the code* — of the two claims, that is the one that can be pointed at.

Modules that depend on one another are listed rather than treated as a fault. A ring can be a deliberate design; the only consequence here is that a snapshot of any one of them holds all of them.

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
 Version   Created                     Directories   Files   Archived   Comment
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
    └── Blog-0002.zip     ← created by modsx:export
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
php artisan modsx:restore Blog 0003 --force   # skip the confirmation, for scripts
```

The sequence is:

1. Back up the module's current state, so the restore is itself reversible.
2. Copy the chosen version out of the backup into a staging area.
3. Move the entire current state aside, in one pass.
4. Move the restored state into place.

Everything is copied out of the backup **before** the application is touched, so a corrupt or incomplete backup is discovered while the current state is still intact. And because step 3 moves the old state aside whole rather than deleting it path by path, a failure during step 4 is undone: you get back exactly what you had, not a half-restored mixture.

Anything the version did not contain is gone afterwards — moved aside and never put back. That is what restoring an exact state has to mean, and `modsx:diff` will tell you in advance what it covers.

That covers **everything the module owns right now**, not just what the version's manifest lists — otherwise the result would match no version at all. Say `Blog 0001` was taken, and then you added these:

| Added after the backup | After `modsx:restore Blog 0001` |
|---|---|
| `resources/views/modsx-blog/extra.blade.php` | gone — inside a directory the module owns |
| `routes/modsx-blog.php` | gone — a standalone file the module owns |
| `app/Models/ModsxBlog/Draft.php` | gone — same, in the Studly form |
| `config/unrelated.php` | untouched — belongs to no module |
| `config/modsx-shop.php` | untouched — belongs to `Shop`, not `Blog` |

The removal is only safe because it is never the last copy. Step 1 above backs the current state up first, so a file swept away by a restore is one version behind, not lost:

```bash
php artisan modsx:restore Blog 0001 --force   # extra.blade.php disappears...
php artisan modsx:backuplist Blog             # ...into version 0002, taken just now
php artisan modsx:restore Blog 0002           # and here it is again
```

The same holds for `modsx:rollback`, which takes a whole safety snapshot before it moves anything.

`modsx:import` is the exception that catches people out: it **never touches the application.** It only unpacks a zip into the backup tree, so nothing in your working copy changes until you restore.

Archived migrations are never restored. They are not read at this step at all.

If the module isn't currently in the application, steps 1 and 3 are skipped and this becomes an **install from backup** — which is how you move a module between projects: copy `modsx-backups/Blog/` across and restore it.

### `modsx:prune`

```bash
php artisan modsx:prune                          # every module, config default
php artisan modsx:prune Blog --keep=5
php artisan modsx:prune --keep=3 --dry-run       # show the plan, change nothing
php artisan modsx:prune --dry-run --json         # machine-readable plan, for CI
php artisan modsx:prune Blog --keep=3 --force    # skip the confirmation, for scripts
```

Lists exactly which versions would go, then asks. The newest version is never removed, whatever `--keep` is set to.

### `modsx:snapshot`

```bash
php artisan modsx:snapshot                                  # the whole project
php artisan modsx:snapshot --comment="before the rewrite"
php artisan modsx:snapshot Blog                             # Blog and everything it needs
php artisan modsx:snapshot --json
```

```
 Module   Version
 Blog     0002      backed up
 Media    0001      unchanged
 User     0001      unchanged

 INFO  Snapshot 0002 taken, holding 3 module(s).
```

A snapshot records **which version of each module was current at one moment.** It answers the question a per-module backup cannot:

> I can't restore Blog from three weeks ago, because back then it depended on a different User.

It **copies nothing.** The versions it names are already in the backup tree, so what is written is a few hundred bytes of version numbers in `modsx-backups/_snapshots/0002.json`. Copying them would double the disk cost and give one version two places to live.

A module that has not changed since its last backup gets no new version, only another reference to the one it had — so a snapshot of an untouched project writes nothing but the snapshot. That is the point: a snapshot nobody minds taking is one that will be there when it is needed.

Naming a module snapshots that module and its dependency closure, worked out exactly as `modsx:deps` shows it.

### `modsx:snapshotlist`

```bash
php artisan modsx:snapshotlist
php artisan modsx:snapshotlist --limit=5
php artisan modsx:snapshotlist --json
```

```
 Snapshot   Created                     Scope           Modules   Comment
 0001       2026-09-03T20:42:51+00:00   whole project   3         before the rewrite
 0002       2026-09-03T20:42:52+00:00   whole project   3         -
 0003       2026-09-03T20:43:01+00:00   Blog            2         -
```

A snapshot shown in red names a version that is no longer in the backup tree, and can no longer be rolled back to. `modsx:prune` will not cause this — it holds those versions back — so it means the backup tree was edited by hand.

### `modsx:rollback`

```bash
php artisan modsx:rollback           # the newest snapshot
php artisan modsx:rollback 0001
php artisan modsx:rollback 0001 --force
```

```
 Module   Current   Snapshot
 Blog     0002      0001        will move
 Media    0001      0001        already there
 User     0001      0001        already there
```

A separate verb from `modsx:restore` on purpose: restoring is one module and one version, rolling back moves everything at once, and those are not two things anyone should be able to confuse at two in the morning. The plan is shown before the prompt, because the number that matters is not how many modules the snapshot holds but how many are somewhere else right now.

What it guarantees, stated exactly:

| Stage | What it does |
|---|---|
| **Checked first** | Every version the snapshot names is confirmed to still exist **before anything is touched**. This is the failure that actually happens, and it is caught while the application is whole. |
| **A way back** | A snapshot of the current state is taken first, and its number is printed at the end. |
| **Per module** | Each module is staged and swapped on its own, so a failure inside one leaves that module untouched. |
| **Between modules** | A failure part-way puts the modules already restored back to where the safety snapshot found them, and says so. |

What it is **not** is a single filesystem transaction — there is no such thing across a dozen directory trees. The last row is compensation, not atomicity, which is why the safety snapshot is named rather than left for you to work out.

It moves **only the modules the snapshot names.** A module created after the snapshot was taken was never part of that moment and is left exactly where it is; a module deleted since is brought back. So the warning above is about files inside a restored module, not about the project as a whole.

`--force` is required in non-interactive use, `--json` included. Machine-readable output is not permission.

**It does not touch your database.** Rolling code back does not roll a schema back, and modsx runs no migrations, in either direction. Archived migrations travel with their module's backup, so what you get back is the files; whether the schema still matches them is yours to judge.

### `modsx:snapshotprune`

```bash
php artisan modsx:snapshotprune --keep=5
php artisan modsx:snapshotprune --keep=5 --dry-run
php artisan modsx:snapshotprune --keep=5 --force
```

Snapshots hold versions back from `modsx:prune`, so this exists to let one go. Removing a snapshot **removes no versions** — it only stops them being held, so the next `modsx:prune` can consider them again.

#### Snapshots and pruning

`modsx:prune` will not delete a version that a snapshot names, the way a tag keeps a commit from being collected. Without that, a rollback would only discover the loss at the moment it needed the version, which is too late to be useful. It says what it left and why:

```
 Shop 0001 ....... kept, held by a snapshot
```

There is no flag to override this. `--force` means *don't ask me* everywhere in this package, and letting it also mean *ignore a safeguard* would let a scripted prune quietly strand every snapshot naming those versions. The way to release them is to let the snapshot go with `modsx:snapshotprune` — after which the next prune considers them again.

A snapshot can still end up naming a version that is gone, if the backup tree is edited by hand. `modsx:doctor` reports that, and `modsx:snapshotprune` clears it.

#### A worked example

Three modules, where `Blog` uses both of the others:

```bash
php artisan modsx:deps
```

```
 INFO  Blog
  Media ......................... found in the code
  User .......................... found in the code

 INFO  Media
  needs nothing else

 INFO  User
  needs nothing else
```

Take a snapshot before starting anything risky:

```bash
php artisan modsx:snapshot --comment="before the payments rewrite"
```

```
 Module   Version
 Blog     0001      backed up
 Media    0001      backed up
 User     0001      backed up

 INFO  Snapshot 0001 taken, holding 3 module(s).
```

Three weeks later, `Blog` and `User` have both moved on. Take another:

```bash
php artisan modsx:snapshot
```

```
 Module   Version
 Blog     0004      backed up
 Media    0001      unchanged
 User     0002      backed up

 INFO  Snapshot 0002 taken, holding 3 module(s).
```

`Media` was not touched in those three weeks, so no new version was written for it — only another reference to `0001`. That is what makes snapshots cheap enough to take before every risky thing you do.

Now the rewrite has to be abandoned. Restoring Blog on its own would not do it:

```bash
php artisan modsx:restore Blog 0001     # Blog 0001 beside a User 0002 it never knew
php artisan modsx:rollback 0001         # the whole moment, as it was
```

```
 Module   Current   Snapshot
 Blog     0004      0001        will move
 Media    0001      0001        already there
 User     0002      0001        will move

 WARN  Anything not in the snapshot is replaced by what was. Files added since are moved aside and not put back.

 INFO  Rolled back to snapshot 0001. 3 module(s) restored.
  The state before this is snapshot ......................................... 0003
```

Snapshot `0003` is the way back out, taken automatically before anything moved. Changed your mind again:

```bash
php artisan modsx:rollback 0003
```

Where things stand at any point:

```bash
php artisan modsx:status
php artisan modsx:snapshotlist
```

```
 Snapshot   Created                     Scope           Modules   Comment
 0001       2026-09-03T09:14:02+00:00   whole project   3         before the payments rewrite
 0002       2026-09-24T16:30:55+00:00   whole project   3         -
 0003       2026-09-24T16:41:18+00:00   whole project   3         before rolling back to snapshot 0001
```

And when the backup tree gets large, let the old moments go before pruning versions:

```bash
php artisan modsx:snapshotprune --keep=5 --dry-run   # see which would go
php artisan modsx:snapshotprune --keep=5             # let them go
php artisan modsx:prune --keep=3                     # now these versions are free
```

| You want | Command |
|---|---|
| Just this module back | `modsx:restore Blog 0004` |
| This module and what it needed then | `modsx:snapshot Blog` first, then `modsx:rollback` |
| The whole project as it was | `modsx:rollback 0001` |
| To see what would move first | `modsx:rollback 0001` and read the table before answering |
| To know what a snapshot would hold | `modsx:deps Blog` |


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

#### Two versions against each other

Give a second version and the application drops out of the comparison entirely — the two versions are compared with each other:

```bash
php artisan modsx:diff Blog 0002 0004
```

The first version is the baseline and the second is what it gets compared with, which is how you would read it aloud: *what happened to Blog between `0002` and `0004`*. The three groups keep their names and change their meaning:

- **Added** — only in `0004`; it appeared after `0002`.
- **Modified** — in both versions, but the contents differ.
- **Removed** — only in `0002`; it was gone by `0004`.

Swapping the two arguments gives the same comparison seen from the other end: what was added becomes what is gone. No restore is involved either way, so nothing here is described in terms of one.

Your working tree is not read at all in this mode, so the answer is the same whatever state the application happens to be in right now.

`--summary` and `--json` work here too. The JSON carries `from` and `to` instead of `version`, so a script can tell the two modes apart by shape alone.

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
- **A module recorded as coming from a version that no longer exists**, after that version was pruned. Not a fault: the record still says truthfully where the working tree came from, and `modsx:status` carries on by measuring against the newest version. Deleting the file is a complete fix.
- **A snapshot naming a version that is no longer there**, which only editing the backup tree by hand can bring about, since `modsx:prune` holds those versions back. The snapshot is still listed and still looks usable, while the one thing it exists for — rolling back to it — is no longer possible. `modsx:snapshotprune` clears it.

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

    // What modsx:scaffold creates when you name no directories yourself. Both
    // placeholders come from the one name you type, which is what stops the
    // two forms from drifting apart. The published file carries a longer list
    // below this one, commented out — Livewire, services, form requests,
    // factories, seeders, tests, resources/css, resources/js, components —
    // so you uncomment what your modules have.
    'scaffold' => [
        'app/Http/Controllers/{Studly}',
        'app/Models/{Studly}',
        'resources/views/{kebab}',
    ],

    // What modsx:make hands each generator. A value of its own runs the
    // generator of that name; a pair runs the generator you name, with the
    // module placed inside one of the application's own directories.
    'generators' => [
        '*' => '{Studly}/',
        'view' => '{kebab}/',
        'config' => '{kebab}-',
        'migration' => '{snake}_',

        'layout' => ['view', 'layouts/{kebab}/'],
        'page' => ['view', 'pages/{kebab}/'],
        'partial' => ['view', 'partials/{kebab}/'],
    ],

    // 4 gives 0001, 0002, ...
    'version_padding' => 4,

    // Default for modsx:prune.
    'prune' => ['keep' => 5],

    // Extra dependencies, for what reading a module's files cannot see.
    // Adds edges to the graph modsx:deps derives; never replaces them.
    'dependencies' => [
        // 'Blog' => ['Search'],
    ],

];
```

Two notes:

- If you change `prefix` after creating modules, rename the existing directories to match. Nothing is found under the old prefix.
- The backup directory is never scanned for modules, wherever you point it — including inside a path that is otherwise scanned.

---

## Limitations

Deliberate, and worth knowing before you rely on this:

- **No database, and therefore no migration restore.** Restoring an older version does not roll back migrations or touch data. Migration *files* are archived into every backup so you can read what a schema used to be, but they are never restored and never deleted — putting an old one back while the schema has moved on would leave your repository and your database disagreeing, with nothing to say so. This is a decision, not a gap to be filled later.
- **A migration matching two modules goes to the longer name.** `Blog` and `BlogPost` coexist happily — files name one module each — but `modsx_blog_post_create_comments_table` matches both, and the longer name wins. That is right for a migration of BlogPost's; if Blog ever needs one whose name begins with BlogPost's, it has to be named differently. This is the only rule you cannot read off a single filename.
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
composer analyse

# Run a command by hand in a Testbench app
composer smoke -- modsx:doctor
```

`composer smoke` rebuilds package discovery before it runs. The test suite registers the service provider itself, so running it leaves behind a package manifest that doesn't mention Modsx — after which `vendor/bin/testbench` can't see the commands at all.

## License

MIT. See [LICENSE](LICENSE).
