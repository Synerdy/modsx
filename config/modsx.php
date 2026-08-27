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

    // Never descended into, wherever they appear under a scan path.
    'exclude' => [
        'vendor', 'node_modules', 'storage', 'bootstrap/cache', '.git', '.idea',
    ],

    // Directories `modsx:scaffold` creates for a new module. Both placeholders
    // are derived from the one name you type, which is what stops the two forms
    // from drifting apart - the mistake modsx:doctor exists to catch.
    //
    //   {Studly} -> ModsxBlog     {kebab} -> modsx-blog
    //
    // Nothing here is required: trim it to the directories you actually use.
    'scaffold' => [
        'app/Http/Controllers/{Studly}',
        'app/Models/{Studly}',
        'resources/views/{kebab}',
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
    'generators' => [
        '*' => '{Studly}/',        // ModsxBlog/PostController
        'view' => '{kebab}/',      // modsx-blog/index
        'config' => '{kebab}-',    // modsx-blog-settings  (a file, not a directory)
        'migration' => '{snake}_', // modsx_blog_create_posts_table
    ],

    // Zero-padded width of version numbers (0001, 0002, ...).
    'version_padding' => 4,

    'prune' => [
        // Default number of most recent versions `modsx:prune` keeps
        // per module when --keep is not passed explicitly.
        'keep' => (int) env('MODSX_PRUNE_KEEP', 5),
    ],

];
