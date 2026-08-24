<?php

declare(strict_types=1);

return [

    // Directory prefix. 'modsx' matches modsx-blog and ModsxBlog.
    'prefix' => env('MODSX_PREFIX', 'modsx'),

    // Where versioned backups are written. Kept outside the framework's own
    // directories so it never collides with another module package's source
    // tree (e.g. nwidart/laravel-modules uses "Modules/").
    'backup_path' => env('MODSX_BACKUP_PATH', base_path('ModulesX')),

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

    // Zero-padded width of version numbers (0001, 0002, ...).
    'version_padding' => 4,

    'prune' => [
        // Default number of most recent versions `modsx:prune` keeps
        // per module when --keep is not passed explicitly.
        'keep' => (int) env('MODSX_PRUNE_KEEP', 5),
    ],

];
