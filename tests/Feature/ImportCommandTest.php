<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;
use Modsx\BackupRepository;

beforeEach(function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('imports an exported zip back into the backup tree', function () {
    $manager = app(BackupManager::class);
    $manager->backup('Blog', comment: 'before refactor');
    $export = $manager->export('Blog');

    File::deleteDirectory($this->root.'/modsx-backups/Blog/0001');

    $output = json_decode(artisanOutput("modsx:import \"{$export['path']}\" --json"), true);

    expect($output['module'])->toBe('Blog')
        ->and($output['version'])->toBe('0001')
        ->and(app(BackupRepository::class)->manifest('Blog', '0001')['comment'])->toBe('before refactor');
});

it('refuses to import over an existing version', function () {
    $manager = app(BackupManager::class);
    $manager->backup('Blog');
    $export = $manager->export('Blog');

    $output = json_decode(artisanOutput("modsx:import \"{$export['path']}\" --json"), true);

    expect($output)->toHaveKey('error');
});

it('fails cleanly for a file that is not a valid export', function () {
    File::put($this->root.'/not-a-zip.txt', 'nope');

    $output = json_decode(artisanOutput("modsx:import \"{$this->root}/not-a-zip.txt\" --json"), true);

    expect($output)->toHaveKey('error');
});

it('fails cleanly for a missing file', function () {
    $output = json_decode(artisanOutput("modsx:import \"{$this->root}/ghost.zip\" --json"), true);

    expect($output)->toHaveKey('error');
});

it('shows a restore hint in the human-readable output', function () {
    $manager = app(BackupManager::class);
    $manager->backup('Blog');
    $export = $manager->export('Blog');
    File::deleteDirectory($this->root.'/modsx-backups/Blog/0001');

    $output = artisanOutput("modsx:import \"{$export['path']}\"");

    expect($output)->toContain('Imported [Blog] as version 0001')
        ->and($output)->toContain('modsx:restore Blog 0001');
});
