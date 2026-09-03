<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;
use Modsx\BackupRepository;
use Modsx\SnapshotManager;

beforeEach(function () {
    foreach (['0001', '0002', '0003', '0004', '0005'] as $version) {
        $this->makeBackupVersion('Blog', $version);
    }
});

it('changes nothing on a dry run', function () {
    $this->artisan('modsx:prune Blog --keep=2 --dry-run')->assertExitCode(0);

    expect(app(BackupRepository::class)->versions('Blog'))->toBe(['0001', '0002', '0003', '0004', '0005']);
});

it('reports the dry-run plan as json', function () {
    $output = json_decode(artisanOutput('modsx:prune Blog --keep=2 --dry-run --json'), true);

    expect($output['dry_run'])->toBeTrue()
        ->and($output['keep'])->toBe(2)
        ->and($output['total'])->toBe(3)
        ->and($output['plan']['Blog'])->toBe(['0001', '0002', '0003']);
});

it('keeps the newest versions when pruning with --force', function () {
    $this->artisan('modsx:prune Blog --keep=2 --force')->assertExitCode(0);

    expect(app(BackupRepository::class)->versions('Blog'))->toBe(['0004', '0005']);
});

it('reports what it removed as json', function () {
    $output = json_decode(artisanOutput('modsx:prune Blog --keep=2 --force --json'), true);

    expect($output['dry_run'])->toBeFalse()
        ->and($output['total'])->toBe(3)
        ->and($output['removed']['Blog'])->toBe(['0001', '0002', '0003']);
});

it('changes nothing without --force in non-interactive mode', function () {
    $this->artisan('modsx:prune Blog --keep=2 --no-interaction')->assertExitCode(0);

    expect(app(BackupRepository::class)->versions('Blog'))->toBe(['0001', '0002', '0003', '0004', '0005']);
});

it('has nothing to prune once every module has --keep versions or fewer', function () {
    $this->artisan('modsx:prune Blog --keep=10 --force')->assertExitCode(0);

    expect(app(BackupRepository::class)->versions('Blog'))->toBe(['0001', '0002', '0003', '0004', '0005']);
});

it('reports no backups found as json', function () {
    File::deleteDirectory($this->root.'/modsx-backups');

    $output = json_decode(artisanOutput('modsx:prune --json'), true);

    expect($output)->toBe(['total' => 0, 'plan' => [], 'held' => []]);
});

it('fails as json when the named module has no backups', function () {
    $output = json_decode(artisanOutput('modsx:prune Ghost --json'), true);

    expect($output)->toHaveKey('error');
});

it('removes a pruned version\'s exported zip along with it', function () {
    File::put($this->root.'/modsx-backups/Blog/Blog-0001.zip', 'zip contents');

    $this->artisan('modsx:prune Blog --keep=2 --force')->assertExitCode(0);

    expect(File::exists($this->root.'/modsx-backups/Blog/Blog-0001.zip'))->toBeFalse();
});

it('sweeps a zip left under the name exports used to carry', function () {
    // Trees written before the module name was part of it would otherwise keep
    // those zips for ever, since nothing else ever looks at them again.
    File::put($this->root.'/modsx-backups/Blog/0001.zip', 'zip contents');

    $this->artisan('modsx:prune Blog --keep=2 --force')->assertExitCode(0);

    expect(File::exists($this->root.'/modsx-backups/Blog/0001.zip'))->toBeFalse();
});

it('says which versions a snapshot kept it from removing', function () {
    // Without this the command silently removes fewer versions than the age
    // rule offered, and the difference reads as a bug rather than a safeguard.
    $this->makeModuleDirectory('resources/views/modsx-shop', 'index.blade.php', 'v1');
    app(SnapshotManager::class)->take();

    foreach (['v2', 'v3'] as $contents) {
        File::put($this->root.'/resources/views/modsx-shop/index.blade.php', $contents);
        app(BackupManager::class)->backup('Shop');
    }

    $output = json_decode(artisanOutput('modsx:prune Shop --keep=1 --force --json'), true);

    expect($output['held']['Shop'])->toBe(['0001'])
        ->and($output['removed']['Shop'] ?? [])->not->toContain('0001');
});

it('does not claim there is little to prune when a snapshot is the reason', function () {
    $this->makeModuleDirectory('resources/views/modsx-shop', 'index.blade.php', 'v1');
    app(SnapshotManager::class)->take();

    File::put($this->root.'/resources/views/modsx-shop/index.blade.php', 'v2');
    app(BackupManager::class)->backup('Shop');

    expect(artisanOutput('modsx:prune Shop --keep=1 --force'))
        ->toContain('held by a snapshot');
});
