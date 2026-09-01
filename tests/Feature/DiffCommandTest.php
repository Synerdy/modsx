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

    $this->artisan('modsx:diff Blog 0001 --json')
        ->assertExitCode(0);

    $output = json_decode(artisanOutput('modsx:diff Blog 0001 --json'), true);

    expect($output['identical'])->toBeTrue()
        ->and($output['added'])->toBe([])
        ->and($output['removed'])->toBe([])
        ->and($output['modified'])->toBe([])
        ->and($output['unchanged'])->toBe(2);
});

it('detects a file whose contents changed', function () {
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2 — rewritten');

    $output = json_decode(artisanOutput('modsx:diff Blog 0001 --json'), true);

    expect($output['identical'])->toBeFalse()
        ->and($output['modified'])->toBe(['resources/views/modsx-blog/index.blade.php'])
        ->and($output['added'])->toBe([])
        ->and($output['removed'])->toBe([])
        ->and($output['unchanged'])->toBe(1);
});

it('detects a file added since the backup', function () {
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/show.blade.php', 'new');

    $output = json_decode(artisanOutput('modsx:diff Blog 0001 --json'), true);

    expect($output['added'])->toBe(['resources/views/modsx-blog/show.blade.php'])
        ->and($output['modified'])->toBe([])
        ->and($output['removed'])->toBe([]);
});

it('detects a file removed since the backup', function () {
    app(BackupManager::class)->backup('Blog');

    File::delete($this->root.'/resources/views/modsx-blog/index.blade.php');

    $output = json_decode(artisanOutput('modsx:diff Blog 0001 --json'), true);

    expect($output['removed'])->toBe(['resources/views/modsx-blog/index.blade.php'])
        ->and($output['added'])->toBe([])
        ->and($output['modified'])->toBe([]);
});

it('detects a whole directory removed since the backup', function () {
    app(BackupManager::class)->backup('Blog');

    File::deleteDirectory($this->root.'/app/Http/Controllers/ModsxBlog');

    $output = json_decode(artisanOutput('modsx:diff Blog 0001 --json'), true);

    expect($output['removed'])->toBe(['app/Http/Controllers/ModsxBlog/PostController.php'])
        ->and($output['unchanged'])->toBe(1);
});

it('emits valid json with no banner in it', function () {
    app(BackupManager::class)->backup('Blog');

    $raw = artisanOutput('modsx:diff Blog 0001 --json');

    expect(json_decode($raw, true))->toBeArray()
        ->and($raw)->not->toContain('█');
});

it('fails when the module has no backups', function () {
    $this->artisan('modsx:diff Blog --json')->assertExitCode(1);
});

it('fails when the requested version does not exist', function () {
    app(BackupManager::class)->backup('Blog');

    $this->artisan('modsx:diff Blog 9999 --json')->assertExitCode(1);
});

it('compares two backup versions with each other', function () {
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    File::put($this->root.'/resources/views/modsx-blog/show.blade.php', 'new');
    File::delete($this->root.'/app/Http/Controllers/ModsxBlog/PostController.php');

    app(BackupManager::class)->backup('Blog');

    $output = json_decode(artisanOutput('modsx:diff Blog 0001 0002 --json'), true);

    expect($output['from'])->toBe('0001')
        ->and($output['to'])->toBe('0002')
        ->and($output)->not->toHaveKey('version')
        ->and($output['added'])->toBe(['resources/views/modsx-blog/show.blade.php'])
        ->and($output['modified'])->toBe(['resources/views/modsx-blog/index.blade.php'])
        ->and($output['removed'])->toBe(['app/Http/Controllers/ModsxBlog/PostController.php']);
});

it('reads the two versions in the order they were given', function () {
    // The first version is the baseline, so naming them the other way round is
    // the same comparison seen from the other end: what was added becomes what
    // is gone. Without this the argument order would carry no meaning at all.
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/show.blade.php', 'new');

    app(BackupManager::class)->backup('Blog');

    $forwards = json_decode(artisanOutput('modsx:diff Blog 0001 0002 --json'), true);
    $backwards = json_decode(artisanOutput('modsx:diff Blog 0002 0001 --json'), true);

    expect($forwards['added'])->toBe(['resources/views/modsx-blog/show.blade.php'])
        ->and($forwards['removed'])->toBe([])
        ->and($backwards['added'])->toBe([])
        ->and($backwards['removed'])->toBe(['resources/views/modsx-blog/show.blade.php']);
});

it('ignores the working tree when two versions are given', function () {
    app(BackupManager::class)->backup('Blog');
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'changed after both backups');

    $versions = json_decode(artisanOutput('modsx:diff Blog 0001 0002 --json'), true);
    $application = json_decode(artisanOutput('modsx:diff Blog 0002 --json'), true);

    expect($versions['identical'])->toBeTrue()
        ->and($application['identical'])->toBeFalse();
});

it('does not describe a version-to-version comparison as a restore', function () {
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/show.blade.php', 'new');

    app(BackupManager::class)->backup('Blog');

    $output = artisanOutput('modsx:diff Blog 0001 0002');

    expect($output)->toContain('Added in 0002')
        ->and($output)->not->toContain('restore would');
});

it('fails when the second version does not exist', function () {
    app(BackupManager::class)->backup('Blog');

    $this->artisan('modsx:diff Blog 0001 9999 --json')->assertExitCode(1);
});
