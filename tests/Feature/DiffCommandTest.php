<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;

beforeEach(function () {
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('reports a module as identical right after a backup', function () {
    app(BackupManager::class)->backup('Blog');

    $this->artisan('modules:diff Blog 0001 --json')
        ->assertExitCode(0);

    $output = json_decode(artisanOutput('modules:diff Blog 0001 --json'), true);

    expect($output['identical'])->toBeTrue()
        ->and($output['added'])->toBe([])
        ->and($output['removed'])->toBe([])
        ->and($output['modified'])->toBe([])
        ->and($output['unchanged'])->toBe(2);
});

it('detects a file whose contents changed', function () {
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2 — rewritten');

    $output = json_decode(artisanOutput('modules:diff Blog 0001 --json'), true);

    expect($output['identical'])->toBeFalse()
        ->and($output['modified'])->toBe(['resources/views/modsx-blog/index.blade.php'])
        ->and($output['added'])->toBe([])
        ->and($output['removed'])->toBe([])
        ->and($output['unchanged'])->toBe(1);
});

it('detects a file added since the backup', function () {
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/show.blade.php', 'new');

    $output = json_decode(artisanOutput('modules:diff Blog 0001 --json'), true);

    expect($output['added'])->toBe(['resources/views/modsx-blog/show.blade.php'])
        ->and($output['modified'])->toBe([])
        ->and($output['removed'])->toBe([]);
});

it('detects a file removed since the backup', function () {
    app(BackupManager::class)->backup('Blog');

    File::delete($this->root.'/resources/views/modsx-blog/index.blade.php');

    $output = json_decode(artisanOutput('modules:diff Blog 0001 --json'), true);

    expect($output['removed'])->toBe(['resources/views/modsx-blog/index.blade.php'])
        ->and($output['added'])->toBe([])
        ->and($output['modified'])->toBe([]);
});

it('detects a whole directory removed since the backup', function () {
    app(BackupManager::class)->backup('Blog');

    File::deleteDirectory($this->root.'/app/Http/Controllers/ModsxBlog');

    $output = json_decode(artisanOutput('modules:diff Blog 0001 --json'), true);

    expect($output['removed'])->toBe(['app/Http/Controllers/ModsxBlog/PostController.php'])
        ->and($output['unchanged'])->toBe(1);
});

it('emits valid json with no banner in it', function () {
    app(BackupManager::class)->backup('Blog');

    $raw = artisanOutput('modules:diff Blog 0001 --json');

    expect(json_decode($raw, true))->toBeArray()
        ->and($raw)->not->toContain('__');
});

it('fails when the module has no backups', function () {
    $this->artisan('modules:diff Blog --json')->assertExitCode(1);
});

it('fails when the requested version does not exist', function () {
    app(BackupManager::class)->backup('Blog');

    $this->artisan('modules:diff Blog 9999 --json')->assertExitCode(1);
});
