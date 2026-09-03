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

it('reports one module name continuing another informationally, without failing', function () {
    // Blog next to BlogPost is a supported layout: files name one module each,
    // and a migration goes to the longest name that claims it.
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-blog-post', 'index.blade.php', 'v1');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['problems'])->toBe(0)
        ->and($output['prefix_collisions'])->toBe([['owner' => 'Blog', 'nested' => 'BlogPost']]);
});

it('reports a file naming a module that does not exist', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
    $this->makeFile('config/modsx-blog-admin.php');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['problems'])->toBe(0)
        ->and($output['unclaimed_files'])->toBe([
            ['module' => 'BlogAdmin', 'path' => 'config/modsx-blog-admin.php'],
        ]);
});

it('says nothing about a file whose module does exist', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-blog-admin', 'index.blade.php', 'v1');
    $this->makeFile('config/modsx-blog.php');
    $this->makeFile('config/modsx-blog-admin.php');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['unclaimed_files'])->toBe([]);
});

it('says nothing about a file that travels with its module directory', function () {
    // Inside the directory it is copied wholesale anyway, so its name naming
    // nothing is of no consequence.
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
    $this->makeFile('resources/views/modsx-blog/modsx-blog-partial.blade.php');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['unclaimed_files'])->toBe([]);
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

it('reports an empty module directory informationally, without failing', function () {
    // Left behind by modsx:scaffold when a module never got any views.
    File::ensureDirectoryExists($this->root.'/resources/views/modsx-blog');
    $this->makeModuleDirectory('app/Models/ModsxBlog', 'Post.php', 'v1');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['problems'])->toBe(0)
        ->and($output['empty_directories'])->toBe([
            ['module' => 'Blog', 'path' => 'resources/views/modsx-blog', 'removed' => false],
        ]);
});

it('does not flag a directory kept alive by a deliberate .gitkeep', function () {
    // File::allFiles() ignores dotfiles by default - the check must ask for
    // hidden files explicitly, or it would remove the very file someone
    // placed there to keep the directory from disappearing.
    File::ensureDirectoryExists($this->root.'/resources/views/modsx-blog');
    File::put($this->root.'/resources/views/modsx-blog/.gitkeep', '');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['empty_directories'])->toBe([]);
});

it('does not flag a directory that has files', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['empty_directories'])->toBe([]);
});

it('leaves empty directories alone without --fix', function () {
    File::ensureDirectoryExists($this->root.'/resources/views/modsx-blog');

    $this->artisan('modsx:doctor')->assertExitCode(0);

    expect(File::isDirectory($this->root.'/resources/views/modsx-blog'))->toBeTrue();
});

it('removes empty module directories with --fix', function () {
    File::ensureDirectoryExists($this->root.'/resources/views/modsx-blog');
    $this->makeModuleDirectory('app/Models/ModsxBlog', 'Post.php', 'v1');

    $output = json_decode(artisanOutput('modsx:doctor --json --fix'), true);

    expect($output['empty_directories'])->toBe([
        ['module' => 'Blog', 'path' => 'resources/views/modsx-blog', 'removed' => true],
    ])
        ->and(File::isDirectory($this->root.'/resources/views/modsx-blog'))->toBeFalse()
        ->and(File::isDirectory($this->root.'/app/Models/ModsxBlog'))->toBeTrue();
});

it('does nothing with --fix when nothing is empty', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');

    $this->artisan('modsx:doctor --fix')->assertExitCode(0);

    expect(File::isDirectory($this->root.'/resources/views/modsx-blog'))->toBeTrue();
});

it('reports a module recorded as coming from a version that was pruned', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');

    app(BackupManager::class)->backup('Blog');
    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    app(BackupManager::class)->backup('Blog');

    app(BackupManager::class)->restore('Blog', '0001');
    app(BackupManager::class)->prune('Blog', 1);

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['stale_state'])->toBe([['module' => 'Blog', 'version' => '0001']])
        ->and($output['problems'])->toBe(0);
});

it('reports no stale state while the recorded version is still there', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');

    app(BackupManager::class)->backup('Blog');

    expect(json_decode(artisanOutput('modsx:doctor --json'), true)['stale_state'])->toBe([]);
});
