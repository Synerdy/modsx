<?php

declare(strict_types=1);

use Modsx\BackupManager;

it('fails when no backups exist', function () {
    $this->artisan('modsx:backuplist --json')->assertExitCode(1);
});

it('lists the backup versions of a module', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
    app(BackupManager::class)->backup('Blog');
    app(BackupManager::class)->backup('Blog', comment: 'before refactor');

    $output = json_decode(artisanOutput('modsx:backuplist Blog --json'), true);

    expect($output['Blog'])->toHaveCount(2)
        ->and($output['Blog'][0]['version'])->toBe('0001')
        ->and($output['Blog'][0]['comment'])->toBeNull()
        ->and($output['Blog'][1]['version'])->toBe('0002')
        ->and($output['Blog'][1]['comment'])->toBe('before refactor');
});

it('limits the result to the newest N versions', function () {
    foreach (['0001', '0002', '0003'] as $version) {
        $this->makeBackupVersion('Blog', $version);
    }

    $output = json_decode(artisanOutput('modsx:backuplist Blog --limit=2 --json'), true);

    expect(array_column($output['Blog'], 'version'))->toBe(['0002', '0003']);
});

it('fails when the named module has no backups', function () {
    $this->artisan('modsx:backuplist Ghost --json')->assertExitCode(1);
});
