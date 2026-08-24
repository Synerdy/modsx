<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupRepository;

beforeEach(function () {
    foreach (['0001', '0002', '0003', '0004', '0005'] as $version) {
        $this->makeBackupVersion('Blog', $version);
    }
});

it('changes nothing on a dry run', function () {
    $this->artisan('modules:prune Blog --keep=2 --dry-run')->assertExitCode(0);

    expect(app(BackupRepository::class)->versions('Blog'))->toBe(['0001', '0002', '0003', '0004', '0005']);
});

it('reports the dry-run plan as json', function () {
    $output = json_decode(artisanOutput('modules:prune Blog --keep=2 --dry-run --json'), true);

    expect($output['dry_run'])->toBeTrue()
        ->and($output['keep'])->toBe(2)
        ->and($output['total'])->toBe(3)
        ->and($output['plan']['Blog'])->toBe(['0001', '0002', '0003']);
});

it('keeps the newest versions when pruning with --force', function () {
    $this->artisan('modules:prune Blog --keep=2 --force')->assertExitCode(0);

    expect(app(BackupRepository::class)->versions('Blog'))->toBe(['0004', '0005']);
});

it('reports what it removed as json', function () {
    $output = json_decode(artisanOutput('modules:prune Blog --keep=2 --force --json'), true);

    expect($output['dry_run'])->toBeFalse()
        ->and($output['total'])->toBe(3)
        ->and($output['removed']['Blog'])->toBe(['0001', '0002', '0003']);
});

it('changes nothing without --force in non-interactive mode', function () {
    $this->artisan('modules:prune Blog --keep=2 --no-interaction')->assertExitCode(0);

    expect(app(BackupRepository::class)->versions('Blog'))->toBe(['0001', '0002', '0003', '0004', '0005']);
});

it('has nothing to prune once every module has --keep versions or fewer', function () {
    $this->artisan('modules:prune Blog --keep=10 --force')->assertExitCode(0);

    expect(app(BackupRepository::class)->versions('Blog'))->toBe(['0001', '0002', '0003', '0004', '0005']);
});

it('reports no backups found as json', function () {
    File::deleteDirectory($this->root.'/ModulesX');

    $output = json_decode(artisanOutput('modules:prune --json'), true);

    expect($output)->toBe(['total' => 0, 'plan' => []]);
});

it('fails as json when the named module has no backups', function () {
    $output = json_decode(artisanOutput('modules:prune Ghost --json'), true);

    expect($output)->toHaveKey('error');
});
