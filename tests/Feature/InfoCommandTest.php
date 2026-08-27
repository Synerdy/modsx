<?php

declare(strict_types=1);

use Modsx\BackupManager;

beforeEach(function () {
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php', 'abc');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'de');
});

it('reports the directories, file count and size of a module', function () {
    $info = json_decode(artisanOutput('modsx:info Blog --json'), true);

    expect($info['module'])->toBe('Blog')
        ->and($info['application']['present'])->toBeTrue()
        ->and($info['application']['file_count'])->toBe(2)
        ->and($info['application']['size_bytes'])->toBe(5)
        ->and($info['application']['directories'])->toHaveCount(2)
        ->and($info['application']['files'])->toBe([])
        ->and($info['backups']['count'])->toBe(0);
});

it('counts a module own files towards its size', function () {
    $this->makeFile('routes/modsx-blog.php', 'xyz');

    $info = json_decode(artisanOutput('modsx:info Blog --json'), true);

    expect($info['application']['files'])->toHaveCount(1)
        ->and($info['application']['files'][0]['path'])->toBe('routes/modsx-blog.php')
        ->and($info['application']['file_count'])->toBe(3)
        ->and($info['application']['size_bytes'])->toBe(8);
});

it('reports backup versions with their sizes', function () {
    app(BackupManager::class)->backup('Blog');
    app(BackupManager::class)->backup('Blog', comment: 'before refactor');

    $info = json_decode(artisanOutput('modsx:info Blog --json'), true);

    expect($info['backups']['count'])->toBe(2)
        ->and($info['backups']['versions'][0]['version'])->toBe('0001')
        ->and($info['backups']['versions'][0]['comment'])->toBeNull()
        ->and($info['backups']['versions'][1]['version'])->toBe('0002')
        ->and($info['backups']['versions'][1]['comment'])->toBe('before refactor')
        ->and($info['backups']['versions'][0]['created_at'])->not->toBeNull()
        ->and($info['backups']['total_size_bytes'])->toBeGreaterThan(0);
});

it('works for a module that exists only in backups', function () {
    app(BackupManager::class)->backup('Blog');
    app(BackupManager::class)->delete('Blog');

    $info = json_decode(artisanOutput('modsx:info Blog --json'), true);

    expect($info['application']['present'])->toBeFalse()
        ->and($info['application']['file_count'])->toBe(0)
        ->and($info['backups']['count'])->toBe(1);
});

it('emits valid json with no banner in it', function () {
    $raw = artisanOutput('modsx:info Blog --json');

    expect(json_decode($raw, true))->toBeArray()
        ->and($raw)->not->toContain('█');
});

it('fails for a module that is neither installed nor backed up', function () {
    $this->artisan('modsx:info Ghost --json')->assertExitCode(1);
});

it('rejects an invalid module name', function () {
    $this->artisan('modsx:info', ['name' => '../etc', '--json' => true])
        ->assertExitCode(1);
});
