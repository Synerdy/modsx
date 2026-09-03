<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;
use Modsx\BackupRepository;
use Modsx\ModuleState;

beforeEach(function () {
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('records the version a backup just wrote', function () {
    app(BackupManager::class)->backup('Blog');

    expect(app(ModuleState::class)->current('Blog'))->toBe('0001');
});

it('records the version a restore put back', function () {
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');

    app(BackupManager::class)->backup('Blog');
    expect(app(ModuleState::class)->current('Blog'))->toBe('0002');

    app(BackupManager::class)->restore('Blog', '0001');

    expect(app(ModuleState::class)->current('Blog'))->toBe('0001');
});

it('records the newest version when a backup found nothing to do', function () {
    // Nothing is written on this path, but the tree does match that version -
    // which is the whole of what the pointer claims.
    app(BackupManager::class)->backup('Blog');
    app(ModuleState::class)->forget('Blog');

    app(BackupManager::class)->backup('Blog', skipUnchanged: true);

    expect(app(ModuleState::class)->current('Blog'))->toBe('0001');
});

it('forgets the version once the module is deleted', function () {
    app(BackupManager::class)->backup('Blog');
    app(BackupManager::class)->delete('Blog');

    expect(app(ModuleState::class)->current('Blog'))->toBeNull()
        ->and(File::exists(app(ModuleState::class)->path('Blog')))->toBeFalse();
});

it('records nothing for an import, which never touches the application', function () {
    app(BackupManager::class)->backup('Blog');
    $zip = app(BackupManager::class)->export('Blog', '0001')['path'];

    app(ModuleState::class)->forget('Blog');
    File::deleteDirectory($this->root.'/modsx-backups/Blog/0001');

    app(BackupManager::class)->import($zip);

    expect(app(ModuleState::class)->current('Blog'))->toBeNull();
});

it('leaves a module made by hand with no state at all', function () {
    // The founding property: discovery is by convention, and this file never
    // takes part in it. A module nobody has backed up has no version to name.
    expect(app(ModuleState::class)->current('Blog'))->toBeNull()
        ->and(File::exists(app(ModuleState::class)->path('Blog')))->toBeFalse();
});

it('survives a prune, which only removes versions and archives', function () {
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    app(BackupManager::class)->backup('Blog');

    app(BackupManager::class)->prune('Blog', 1);

    expect(app(ModuleState::class)->current('Blog'))->toBe('0002');
});

it('reads a version that is not digits as no pointer at all', function () {
    app(BackupManager::class)->backup('Blog');

    File::put(app(ModuleState::class)->path('Blog'), json_encode(['current' => '../../escaped']));

    expect(app(ModuleState::class)->current('Blog'))->toBeNull();
});

it('reads a file that is not a state record as no pointer at all', function () {
    app(BackupManager::class)->backup('Blog');

    File::put(app(ModuleState::class)->path('Blog'), 'not json at all');

    expect(app(ModuleState::class)->current('Blog'))->toBeNull();
});

it('records what put the module where it is', function () {
    app(BackupManager::class)->backup('Blog');

    expect(app(ModuleState::class)->read('Blog')['by'])->toBe('backup');

    app(BackupManager::class)->restore('Blog', '0001');

    expect(app(ModuleState::class)->read('Blog')['by'])->toBe('restore');
});

it('keeps the state file out of the versions a module lists', function () {
    app(BackupManager::class)->backup('Blog');

    expect(app(BackupRepository::class)->versions('Blog'))->toBe(['0001']);
});
