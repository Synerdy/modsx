<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;

beforeEach(function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('exports the newest version by default', function () {
    app(BackupManager::class)->backup('Blog');
    app(BackupManager::class)->backup('Blog');

    $output = json_decode(artisanOutput('modsx:export Blog --json'), true);

    expect($output['module'])->toBe('Blog')
        ->and($output['version'])->toBe('0002')
        ->and(File::exists($output['path']))->toBeTrue();
});

it('exports an explicit version', function () {
    app(BackupManager::class)->backup('Blog');
    app(BackupManager::class)->backup('Blog');

    $output = json_decode(artisanOutput('modsx:export Blog 0001 --json'), true);

    expect($output['version'])->toBe('0001');
});

it('fails cleanly for a module with no backups', function () {
    $output = json_decode(artisanOutput('modsx:export Blog --json'), true);

    expect($output)->toHaveKey('error');
});

it('fails cleanly for a version that does not exist', function () {
    app(BackupManager::class)->backup('Blog');

    $output = json_decode(artisanOutput('modsx:export Blog 9999 --json'), true);

    expect($output)->toHaveKey('error');
});

it('reports the exported path in the human-readable output', function () {
    app(BackupManager::class)->backup('Blog');

    $output = artisanOutput('modsx:export Blog');

    expect($output)->toContain('Exported [Blog] version 0001');
});
