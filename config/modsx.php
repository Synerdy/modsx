<?php

declare(strict_types=1);

return [

    // Directory prefix. 'modsx' matches modsx-blog and ModsxBlog.
    'prefix' => env('MODSX_PREFIX', 'modsx'),

    // Where versioned backups are written. Kept outside the framework's own
    // directories so it never collides with another module package's source
    // tree (e.g. nwidart/laravel-modules uses "Modules/").
    'backup_path' => env('MODSX_BACKUP_PATH', base_path('modsx-backups')),

    // Only these directories are scanned. Keeping this list tight keeps
    // discovery fast and prevents Modsx from ever walking into storage/framework,
    // .git, or other places it has no business looking.
    'scan_paths' => [
        'app', 'resources', 'routes', 'database', 'config', 'lang', 'public', 'tests',
    ],

    // Never descended into, wherever they appear under a scan path. A bare
    // name matches a directory of that name at any depth; a name with a slash
    // matches that shape of path below a scanned directory.
    //
    // These are not idle: "storage" is what keeps discovery out of the
    // public/storage symlink, and "build" out of whatever the asset bundler
    // wrote there - both live under public/, which is scanned.
    'exclude' => [
        'vendor', 'node_modules', 'storage', 'build', 'bootstrap/cache', '.git', '.idea',
    ],

    // Directories `modsx:scaffold` creates for a new module. Both placeholders
    // are derived from the one name you type, which is what stops the two forms
    // from drifting apart - the mistake modsx:doctor exists to catch.
    //
    //   {Studly} -> ModsxBlog     {kebab} -> modsx-blog
    //
    // Nothing here is required: trim it to the directories you actually use.
    // The list is deliberately short. A directory nobody fills in is invisible
    // to git and reported by `modsx:doctor`, so anything not used by nearly
    // every module is offered below rather than created for you.
    'scaffold' => [
        'app/Http/Controllers/{Studly}',
        'app/Models/{Studly}',
        'resources/views/{kebab}',

        // Uncomment whichever your modules actually have:
        //
        // 'app/Livewire/{Studly}',
        // 'app/Services/{Studly}',
        // 'app/Http/Requests/{Studly}',
        // 'app/Http/Middleware/{Studly}',
        // 'database/factories/{Studly}',
        // 'database/seeders/{Studly}',
        // 'tests/Feature/{Studly}',
        //
        // 'resources/css/{kebab}',
        // 'resources/js/{kebab}',
        //
        // Views follow the same shape as everything else: the framework's
        // directory first, the module inside it. That is what makes
        // <x-modsx-blog.card> resolve, and it is why a module's layouts sit in
        // layouts/modsx-blog rather than in modsx-blog/layouts - the latter
        // would be the one place in the whole convention where the module came
        // first. The kit's own layouts/app.blade.php stays where it is: it
        // carries no prefix, so no module claims it.
        // 'resources/views/components/{kebab}',
        // 'resources/views/layouts/{kebab}',
        // 'resources/views/partials/{kebab}',
        // 'resources/views/pages/{kebab}',
    ],

    // How `modsx:make` writes the module into the name it hands to Laravel's
    // own generator. The key is the generator, without the "make:" prefix;
    // '*' is the rule for everything not listed. This table is the whole
    // naming convention in one place - which is the point of the command:
    //
    //   modsx:make controller Blog/PostController
    //     -> make:controller ModsxBlog/PostController
    //
    // Add an entry for a generator from another package (make:livewire,
    // make:filament-resource) and it follows the convention too. Anything
    // unlisted gets '*', which is right for any PHP class.
    // A value of its own runs the generator of the same name. A pair runs the
    // generator you name, with the module placed inside one of the
    // application's own directories - which is where a module's layout goes,
    // the framework's directory first and the module second, exactly as in
    // resources/css/modsx-blog. Add your own; the name is yours to pick.
    'generators' => [
        '*' => '{Studly}/',        // ModsxBlog/PostController
        'view' => '{kebab}/',      // modsx-blog/index
        'config' => '{kebab}-',    // modsx-blog-settings  (a file, not a directory)
        'migration' => '{snake}_', // modsx_blog_create_posts_table

        // modsx:make layout blog.app  ->  views/layouts/modsx-blog/app.blade.php
        //
        // Not "component": make:component is a generator of Laravel's own, and
        // it already lands correctly - it writes the class, and Laravel derives
        // views/components/modsx-blog/ from where that class went.
        'layout' => ['view', 'layouts/{kebab}/'],
        'page' => ['view', 'pages/{kebab}/'],
        'partial' => ['view', 'partials/{kebab}/'],
    ],

    // Zero-padded width of version numbers (0001, 0002, ...).
    'version_padding' => 4,

    'prune' => [
        // Default number of most recent versions `modsx:prune` keeps
        // per module when --keep is not passed explicitly.
        'keep' => (int) env('MODSX_PRUNE_KEEP', 5),
    ],

];
