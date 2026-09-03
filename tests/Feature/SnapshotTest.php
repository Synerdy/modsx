<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;
use Modsx\BackupRepository;
use Modsx\Exceptions\ModsxException;
use Modsx\SnapshotManager;
use Modsx\SnapshotRepository;

beforeEach(function () {
    $this->makeModuleDirectory('resources/views/modsx-user', 'card.blade.php', 'user v1');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', "blog v1 @include('modsx-user.card')");
});

it('records the version every module is at', function () {
    $result = app(SnapshotManager::class)->take();

    expect($result['snapshot'])->toBe('0001')
        ->and($result['modules'])->toBe(['Blog' => '0001', 'User' => '0001'])
        ->and($result['created'])->toEqualCanonicalizing(['Blog', 'User']);
});

it('writes no new versions when nothing has changed', function () {
    app(SnapshotManager::class)->take();

    $second = app(SnapshotManager::class)->take();

    expect($second['snapshot'])->toBe('0002')
        ->and($second['modules'])->toBe(['Blog' => '0001', 'User' => '0001'])
        ->and($second['created'])->toBe([]);
});

it('holds only a module and what it needs when one is named', function () {
    $this->makeModuleDirectory('resources/views/modsx-shop', 'index.blade.php', 'unrelated');

    $result = app(SnapshotManager::class)->take('Blog');

    expect(array_keys($result['modules']))->toBe(['Blog', 'User'])
        ->and($result['root'])->toBe('Blog');
});

it('records the graph as it was at the time', function () {
    app(SnapshotManager::class)->take();

    expect(app(SnapshotRepository::class)->read('0001')['dependencies']['Blog'])->toBe(['User']);
});

it('puts a module back together with the version of what it needed then', function () {
    // The problem this exists for: Blog from three weeks ago depended on a
    // different User than the one in the application now.
    app(SnapshotManager::class)->take(comment: 'three weeks ago');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'blog v2');
    File::put($this->root.'/resources/views/modsx-user/card.blade.php', 'user v2');
    app(SnapshotManager::class)->take();

    app(SnapshotManager::class)->rollback('0001');

    expect(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toContain('blog v1')
        ->and(File::get($this->root.'/resources/views/modsx-user/card.blade.php'))->toBe('user v1');
});

it('leaves a snapshot of the state it found before rolling back', function () {
    app(SnapshotManager::class)->take();

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'blog v2');

    $result = app(SnapshotManager::class)->rollback('0001');

    expect($result['safety'])->toBe('0002');

    app(SnapshotManager::class)->rollback($result['safety']);

    expect(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toBe('blog v2');
});

it('refuses a rollback when a version it names has gone, and changes nothing', function () {
    app(SnapshotManager::class)->take();

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'blog v2');
    app(BackupManager::class)->backup('Blog');

    File::deleteDirectory($this->root.'/modsx-backups/Blog/0001');

    app(SnapshotManager::class)->rollback('0001');
})->throws(ModsxException::class, 'no longer in the backup tree');

it('keeps the application untouched when it refuses', function () {
    app(SnapshotManager::class)->take();

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'blog v2');
    app(BackupManager::class)->backup('Blog');
    File::deleteDirectory($this->root.'/modsx-backups/Blog/0001');

    try {
        app(SnapshotManager::class)->rollback('0001');
    } catch (ModsxException) {
        // expected
    }

    expect(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toBe('blog v2')
        ->and(app(SnapshotRepository::class)->ids())->toBe(['0001']);
});

it('holds a snapshotted version back from prune', function () {
    app(SnapshotManager::class)->take();

    foreach (['v2', 'v3', 'v4'] as $contents) {
        File::put($this->root.'/resources/views/modsx-blog/index.blade.php', $contents);
        app(BackupManager::class)->backup('Blog');
    }

    $removed = app(BackupManager::class)->prune('Blog', 1);

    expect($removed)->not->toContain('0001')
        ->and(app(BackupRepository::class)->versions('Blog'))->toContain('0001');
});

it('reports which versions a snapshot is keeping from prune', function () {
    app(SnapshotManager::class)->take();

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    app(BackupManager::class)->backup('Blog');

    expect(app(BackupManager::class)->heldFromPrune('Blog', 1))->toBe(['0001'])
        ->and(app(BackupManager::class)->prune('Blog', 1))->toBe([]);
});

it('reports a snapshot left naming a version somebody deleted by hand', function () {
    app(SnapshotManager::class)->take();

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    app(BackupManager::class)->backup('Blog');
    File::deleteDirectory($this->root.'/modsx-backups/Blog/0001');

    expect(app(SnapshotRepository::class)->dangling())
        ->toBe([['snapshot' => '0001', 'module' => 'Blog', 'version' => '0001']]);
});

it('keeps the newest snapshots and lets the rest go', function () {
    foreach (range(1, 4) as $n) {
        File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v'.$n);
        app(SnapshotManager::class)->take();
    }

    $removed = app(SnapshotManager::class)->prune(2);

    expect($removed)->toBe(['0001', '0002'])
        ->and(app(SnapshotRepository::class)->ids())->toBe(['0003', '0004']);
});

it('removes no versions when a snapshot is let go', function () {
    app(SnapshotManager::class)->take();

    app(SnapshotManager::class)->prune(1);

    expect(app(BackupRepository::class)->versions('Blog'))->toBe(['0001']);
});

it('refuses to read a snapshot naming a version it could not have written', function () {
    app(SnapshotManager::class)->take();

    File::put(
        app(SnapshotRepository::class)->pathFor('0001'),
        json_encode(['modules' => ['Blog' => '../../escaped']]),
    );

    app(SnapshotRepository::class)->read('0001');
})->throws(ModsxException::class, 'Refusing to read it');

it('keeps the snapshot directory out of the module listing', function () {
    app(SnapshotManager::class)->take();

    expect(app(BackupRepository::class)->modules())->toBe(['Blog', 'User']);
});

it('leaves a module the snapshot never held exactly where it is', function () {
    // A rollback restores what the snapshot named. It is not a wipe: a module
    // created afterwards was never part of that moment and is not removed by
    // returning to it.
    app(SnapshotManager::class)->take();

    $this->makeModuleDirectory('resources/views/modsx-shop', 'index.blade.php', 'added later');

    app(SnapshotManager::class)->rollback('0001');

    expect(File::isDirectory($this->root.'/resources/views/modsx-shop'))->toBeTrue();
});

it('brings back a module that was deleted after the snapshot', function () {
    app(SnapshotManager::class)->take();

    app(BackupManager::class)->delete('User');
    expect(File::isDirectory($this->root.'/resources/views/modsx-user'))->toBeFalse();

    app(SnapshotManager::class)->rollback('0001');

    expect(File::get($this->root.'/resources/views/modsx-user/card.blade.php'))->toBe('user v1');
});
