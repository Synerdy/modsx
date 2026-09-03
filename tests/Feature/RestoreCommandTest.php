<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;
use Modsx\BackupRepository;
use Modsx\ModuleLocator;

beforeEach(function () {
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('restores the newest version by default', function () {
    app(BackupManager::class)->backup('Blog');
    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    app(BackupManager::class)->backup('Blog');

    $this->artisan('modsx:restore Blog --force --no-interaction')->assertExitCode(0);

    expect(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toBe('v2');
});

it('restores a specific version', function () {
    app(BackupManager::class)->backup('Blog');
    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    app(BackupManager::class)->backup('Blog');

    $this->artisan('modsx:restore Blog 0001 --force')->assertExitCode(0);

    expect(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toBe('v1');
});

it('backs up the current state before overwriting it', function () {
    app(BackupManager::class)->backup('Blog');

    $this->artisan('modsx:restore Blog 0001 --force')->assertExitCode(0);

    expect(app(BackupRepository::class)->versions('Blog'))->toBe(['0001', '0002']);
});

it('installs a module from backup when it is absent from the application', function () {
    app(BackupManager::class)->backup('Blog');
    app(BackupManager::class)->delete('Blog');

    $this->artisan('modsx:restore Blog --force --no-interaction')->assertExitCode(0);

    expect(app(ModuleLocator::class)->exists('Blog'))->toBeTrue();
});

it('changes nothing without --force in non-interactive mode', function () {
    app(BackupManager::class)->backup('Blog');
    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'scratch');

    $this->artisan('modsx:restore Blog 0001 --no-interaction')->assertExitCode(0);

    expect(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toBe('scratch')
        ->and(app(BackupRepository::class)->versions('Blog'))->toBe(['0001']);
});

it('fails when the module has no backups', function () {
    $this->artisan('modsx:restore Blog --force')->assertExitCode(1);
});

it('fails when the requested version does not exist', function () {
    app(BackupManager::class)->backup('Blog');

    $this->artisan('modsx:restore Blog 9999 --force')->assertExitCode(1);
});

it('removes a file added inside a module directory since the version', function () {
    app(BackupManager::class)->backup('Blog');

    $this->makeFile('resources/views/modsx-blog/extra.blade.php', 'added later');

    app(BackupManager::class)->restore('Blog', '0001');

    expect(File::exists($this->root.'/resources/views/modsx-blog/extra.blade.php'))->toBeFalse();
});

it('removes a standalone file of the module the version never held', function () {
    // Restore takes down everything the locator attributes to the module right
    // now, not only what the manifest lists - otherwise a file added since
    // would survive a restore and the result would match no version at all.
    app(BackupManager::class)->backup('Blog');

    $this->makeFile('routes/modsx-blog.php', 'added later');

    app(BackupManager::class)->restore('Blog', '0001');

    expect(File::exists($this->root.'/routes/modsx-blog.php'))->toBeFalse();
});

it('leaves a file belonging to no module alone', function () {
    app(BackupManager::class)->backup('Blog');

    $this->makeFile('config/unrelated.php', 'nobody owns this');

    app(BackupManager::class)->restore('Blog', '0001');

    expect(File::exists($this->root.'/config/unrelated.php'))->toBeTrue();
});

it('keeps what it removed in the backup the command takes first', function () {
    // The removal is only safe because it is not the last copy: the command
    // backs the current state up before restoring, so a file swept away by a
    // restore is one version behind, not gone.
    app(BackupManager::class)->backup('Blog');

    $this->makeFile('resources/views/modsx-blog/extra.blade.php', 'a whole day of work');

    $this->artisan('modsx:restore Blog 0001 --force')->assertExitCode(0);

    expect(File::exists($this->root.'/resources/views/modsx-blog/extra.blade.php'))->toBeFalse()
        ->and(File::get($this->root.'/modsx-backups/Blog/0002/resources/views/modsx-blog/extra.blade.php'))
        ->toBe('a whole day of work');
});
