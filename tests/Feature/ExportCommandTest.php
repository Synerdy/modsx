<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;
use Modsx\BackupRepository;

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

it('leaves version numbering alone, zip or no zip', function () {
    // A version is a directory; the zip beside it is a derived artefact. If
    // listing ever picked the file up, the zip would read as a version and
    // the next backup would be numbered over the top of a real one.
    $manager = app(BackupManager::class);
    $manager->backup('Blog');
    $manager->backup('Blog');
    $manager->export('Blog', '0002');

    $backups = app(BackupRepository::class);

    expect(File::exists($this->root.'/modsx-backups/Blog/Blog-0002.zip'))->toBeTrue()
        ->and($backups->versions('Blog'))->toBe(['0001', '0002'])
        ->and($backups->nextVersion('Blog'))->toBe('0003');
});

it('does not report the zip as a stray directory', function () {
    // modsx:doctor lists what sits in a backup tree without being a version.
    // The zip belongs there, so it must not be named.
    $manager = app(BackupManager::class);
    $manager->backup('Blog');
    $manager->export('Blog');

    $output = json_decode(artisanOutput('modsx:doctor --json'), true);

    expect($output['stray_backup_directories'])->toBe([]);
});
