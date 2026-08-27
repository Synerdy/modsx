<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;
use Modsx\BackupRepository;

it('reports no problems for a clean application', function () {
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');

    $this->artisan('modsx:doctor')->assertExitCode(0);
});

it('reports no problems as json', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['problems'])->toBe(0)
        ->and($output['ambiguous_names'])->toBe([]);
});

it('flags module names that differ only in word boundaries', function () {
    $this->makeModuleDirectory('resources/views/modsx-user-profile', 'index.blade.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-userprofile', 'index.blade.php', 'v1');

    $this->artisan('modsx:doctor')->assertExitCode(1);
});

it('reports ambiguous names as json with both variants and their paths', function () {
    $this->makeModuleDirectory('resources/views/modsx-user-profile', 'index.blade.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-userprofile', 'index.blade.php', 'v1');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['problems'])->toBe(1)
        ->and($output['ambiguous_names'])->toHaveCount(1)
        ->and($output['ambiguous_names'][0]['names'])->toEqualCanonicalizing(['UserProfile', 'Userprofile']);
});

it('lists modules using only one directory form informationally, without failing', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['problems'])->toBe(0)
        ->and($output['single_form_modules'])->toContain(['module' => 'Blog', 'kebab' => true, 'studly' => false]);
});

it('lists orphaned backups informationally, without failing', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
    app(BackupManager::class)->backup('Blog');
    app(BackupManager::class)->delete('Blog');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['problems'])->toBe(0)
        ->and($output['orphaned_backups'])->toBe([['module' => 'Blog', 'versions' => 1]]);
});

it('flags one module sitting inside another module prefix', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-blog-post', 'index.blade.php', 'v1');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['problems'])->toBe(1)
        ->and($output['prefix_collisions'])->toBe([['owner' => 'Blog', 'nested' => 'BlogPost']]);
});

it('flags backup trees that differ only in letter case', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
    $this->makeBackupVersion('BLog', '0001');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['problems'])->toBeGreaterThan(0)
        ->and($output['case_collisions'])->toContain(['module' => 'Blog', 'existing' => 'BLog']);
});

it('flags a backup version whose manifest is unreadable', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/modsx-backups/Blog/0001/modsx.json', 'not json');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['problems'])->toBe(1)
        ->and($output['broken_backups'])->toBe([['module' => 'Blog', 'version' => '0001']]);
});

it('tells you which migrations the naming convention is silently missing', function () {
    // Without this the convention just quietly does nothing and nobody finds
    // out why their migration was never archived.
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
    $this->makeFile('database/migrations/2026_01_01_000000_create_modsx_blog_posts_table.php');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['problems'])->toBe(0)
        ->and($output['misnamed_migrations'])->toBe([[
            'module' => 'Blog',
            'path' => 'database/migrations/2026_01_01_000000_create_modsx_blog_posts_table.php',
            'suggestion' => '2026_01_01_000000_modsx_blog_create_posts_table.php',
        ]]);
});

it('does not flag a migration that already follows the convention', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
    $this->makeFile('database/migrations/2026_01_01_000000_modsx_blog_posts_table.php');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['misnamed_migrations'])->toBe([]);
});

it('reports backups taken under a different prefix, without failing', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
    app(BackupManager::class)->backup('Blog');

    $manifest = app(BackupRepository::class)->manifest('Blog', '0001');
    $manifest['prefix'] = 'mod';
    app(BackupRepository::class)->writeManifest(
        app(BackupRepository::class)->versionPath('Blog', '0001'),
        $manifest
    );

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['problems'])->toBe(0)
        ->and($output['foreign_prefix_backups'])->toBe([
            ['module' => 'Blog', 'version' => '0001', 'prefix' => 'mod'],
        ]);
});

it('reports directories in the backup tree that are not versions', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
    $this->makeBackupVersion('Blog', '0001');
    $this->makeBackupVersion('Blog', 'old-0002');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['stray_backup_directories'])->toBe([
        ['module' => 'Blog', 'directory' => 'old-0002'],
    ]);
});
