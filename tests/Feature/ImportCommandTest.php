<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;
use Modsx\BackupRepository;
use Modsx\Exceptions\ModsxException;

beforeEach(function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('refuses a zip whose manifest names a version that climbs out of the tree', function () {
    // A version becomes a directory name, and modsx:import reads it out of a
    // manifest somebody else wrote. Unchecked, "../../.." put the imported
    // files outside the project altogether - proven before this was fixed.
    $escapee = 'modsx-escaped-'.bin2hex(random_bytes(4));
    $zipPath = $this->root.'/hostile.zip';

    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('modsx.json', (string) json_encode([
        'module' => 'Blog',
        'version' => '../../../'.$escapee,
        'paths' => ['resources/views/modsx-blog'],
    ]));
    $zip->addFromString('resources/views/modsx-blog/pwned.blade.php', 'escaped');
    $zip->close();

    expect(fn () => app(BackupManager::class)->import($zipPath))
        ->toThrow(ModsxException::class, 'Invalid version')
        ->and(File::exists(dirname(dirname($this->root)).'/'.$escapee))->toBeFalse();
});

it('refuses to restore a version whose manifest climbs out of the project', function () {
    // The other half of the same hole: restore writes to base_path() of every
    // path the manifest lists, so "../../.env" would be written there.
    $version = $this->makeBackupVersion('Blog', '0001');

    app(BackupRepository::class)->writeManifest($version, [
        'module' => 'Blog',
        'version' => '0001',
        'paths' => ['../../escaped'],
        'files' => [],
    ]);

    expect(fn () => app(BackupManager::class)->restore('Blog', '0001'))
        ->toThrow(ModsxException::class, 'does not stay inside the project');
});

it('refuses a manifest path that is absolute', function () {
    $version = $this->makeBackupVersion('Blog', '0001');

    app(BackupRepository::class)->writeManifest($version, [
        'module' => 'Blog',
        'version' => '0001',
        'paths' => ['/etc/passwd'],
        'files' => [],
    ]);

    expect(fn () => app(BackupManager::class)->pathsInBackup('Blog', '0001'))
        ->toThrow(ModsxException::class, 'does not stay inside the project');
});

it('refuses a manifest path carrying a Windows drive letter', function () {
    // A manifest written on one platform is read on another, and C:\ survives
    // the trip looking like an ordinary relative path to a POSIX check.
    $version = $this->makeBackupVersion('Blog', '0001');

    app(BackupRepository::class)->writeManifest($version, [
        'module' => 'Blog',
        'version' => '0001',
        'paths' => [],
        'files' => ['C:\\Windows\\evil.txt'],
    ]);

    expect(fn () => app(BackupManager::class)->filesInBackup('Blog', '0001'))
        ->toThrow(ModsxException::class, 'does not stay inside the project');
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
