<?php

declare(strict_types=1);

use Modsx\BackupManager;

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
