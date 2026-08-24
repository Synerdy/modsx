<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;
use Modsx\BackupRepository;
use Modsx\Exceptions\ModsxException;
use Modsx\ModuleLocator;

beforeEach(function () {
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php', '<?php // v1');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('finds both directory forms of a module', function () {
    expect(app(ModuleLocator::class)->paths('Blog'))->toBe([
        'app/Http/Controllers/ModsxBlog',
        'resources/views/modsx-blog',
    ]);
});

it('never treats the backup tree as application code, even inside a scanned path', function () {
    // Worst case: someone points the backup directory at a directory that is
    // itself scanned. The backup must still not be discovered as a module.
    config()->set('modsx.backup_path', $this->root.'/resources/modsx-backups');

    $this->makeModuleDirectory('resources/modsx-backups/Blog/0001/resources/views/modsx-blog', 'index.blade.php', 'old');

    expect(app(ModuleLocator::class)->paths('Blog'))->toBe([
        'app/Http/Controllers/ModsxBlog',
        'resources/views/modsx-blog',
    ]);
});

it('copies every directory of a module and writes a manifest', function () {
    $result = app(BackupManager::class)->backup('Blog');

    expect($result['version'])->toBe('0001')
        ->and(File::isDirectory($this->root.'/modsx-backups/Blog/0001/app/Http/Controllers/ModsxBlog'))->toBeTrue()
        ->and(File::isDirectory($this->root.'/modsx-backups/Blog/0001/resources/views/modsx-blog'))->toBeTrue();

    $manifest = app(BackupRepository::class)->manifest('Blog', '0001');

    expect($manifest['module'])->toBe('Blog')
        ->and($manifest['paths'])->toBe($result['paths'])
        ->and($manifest['prefix'])->toBe('modsx');
});

it('leaves no staging directory behind', function () {
    app(BackupManager::class)->backup('Blog');

    $leftovers = array_filter(
        File::directories($this->root.'/modsx-backups/Blog'),
        static fn (string $path): bool => str_contains(basename($path), '.modsx-tmp-')
    );

    expect($leftovers)->toBeEmpty();
});

it('refuses to write over something already occupying the target path', function () {
    $this->makeBackupVersion('Blog', '0001');

    // The next number is 0002; something non-numeric already sits there, so
    // versions() cannot see it. Merging into it would corrupt whatever it is.
    File::put($this->root.'/modsx-backups/Blog/0002', 'not a version directory');

    app(BackupManager::class)->backup('Blog');
})->throws(ModsxException::class);

it('fails loudly when the module is not in the application', function () {
    app(BackupManager::class)->backup('DoesNotExist');
})->throws(ModsxException::class);

it('restores the chosen version over the current state', function () {
    $manager = app(BackupManager::class);

    $manager->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    $manager->backup('Blog');

    $manager->restore('Blog', '0001');

    expect(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toBe('v1');
});

it('defaults to the newest version', function () {
    $manager = app(BackupManager::class);

    $manager->backup('Blog');
    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    $manager->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'scratch');
    $result = $manager->restore('Blog');

    expect($result['version'])->toBe('0002')
        ->and(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toBe('v2');
});

it('does not leave stale files behind when restoring', function () {
    $manager = app(BackupManager::class);

    $manager->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/extra.blade.php', 'added later');

    $manager->restore('Blog', '0001');

    expect(File::exists($this->root.'/resources/views/modsx-blog/extra.blade.php'))->toBeFalse();
});

it('installs a module from backup when it is absent from the application', function () {
    $manager = app(BackupManager::class);

    $manager->backup('Blog');
    $manager->delete('Blog');

    expect(app(ModuleLocator::class)->exists('Blog'))->toBeFalse();

    $manager->restore('Blog', '0001');

    expect(app(ModuleLocator::class)->exists('Blog'))->toBeTrue()
        ->and(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toBe('v1');
});

it('removes every directory of a module when deleting', function () {
    $removed = app(BackupManager::class)->delete('Blog');

    expect($removed)->toHaveCount(2)
        ->and(File::isDirectory($this->root.'/resources/views/modsx-blog'))->toBeFalse()
        ->and(File::isDirectory($this->root.'/app/Http/Controllers/ModsxBlog'))->toBeFalse();
});

it('keeps the newest versions when pruning and never the oldest', function () {
    foreach (['0001', '0002', '0003', '0004', '0005'] as $version) {
        $this->makeBackupVersion('Blog', $version);
    }

    $removed = app(BackupManager::class)->prune('Blog', keep: 2);

    expect($removed)->toBe(['0001', '0002', '0003'])
        ->and(app(BackupRepository::class)->versions('Blog'))->toBe(['0004', '0005']);
});

it('changes nothing on a dry run', function () {
    foreach (['0001', '0002', '0003'] as $version) {
        $this->makeBackupVersion('Blog', $version);
    }

    $removed = app(BackupManager::class)->prune('Blog', keep: 1, dryRun: true);

    expect($removed)->toBe(['0001', '0002'])
        ->and(app(BackupRepository::class)->versions('Blog'))->toBe(['0001', '0002', '0003']);
});

it('always keeps at least one version', function () {
    $this->makeBackupVersion('Blog', '0001');

    expect(app(BackupManager::class)->prune('Blog', keep: 0))->toBe([])
        ->and(app(BackupRepository::class)->versions('Blog'))->toBe(['0001']);
});
