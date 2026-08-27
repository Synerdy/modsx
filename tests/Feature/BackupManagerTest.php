<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;
use Modsx\BackupRepository;
use Modsx\Exceptions\ModsxException;
use Modsx\ModsxServiceProvider;
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
        ->and($manifest['prefix'])->toBe('modsx')
        // Regression: this was a hardcoded constant that went stale at every
        // release from v0.2.0 onward. It must come from the same place the
        // command banner does, not be duplicated.
        ->and($manifest['modsx_version'])->toBe(ModsxServiceProvider::version())
        ->and($manifest['comment'])->toBeNull();
});

it('copies the module files alongside its directories', function () {
    $this->makeFile('routes/modsx-blog.php', 'routes v1');

    $result = app(BackupManager::class)->backup('Blog');

    expect($result['files'])->toBe(['routes/modsx-blog.php'])
        ->and(File::get($this->root.'/modsx-backups/Blog/0001/routes/modsx-blog.php'))->toBe('routes v1')
        ->and(app(BackupRepository::class)->manifest('Blog', '0001')['files'])->toBe(['routes/modsx-blog.php']);
});

it('archives migrations away from the restorable content', function () {
    $this->makeFile('database/migrations/2026_01_01_000000_modsx_blog_posts_table.php', 'schema');

    $result = app(BackupManager::class)->backup('Blog');

    expect($result['archived'])->toBe(['database/migrations/2026_01_01_000000_modsx_blog_posts_table.php'])
        ->and(File::get($this->root.'/modsx-backups/Blog/0001/_archive/database/migrations/2026_01_01_000000_modsx_blog_posts_table.php'))->toBe('schema')
        // Not under the restorable half of the version, so restore() cannot
        // reach it even if it wanted to.
        ->and(File::exists($this->root.'/modsx-backups/Blog/0001/database/migrations/2026_01_01_000000_modsx_blog_posts_table.php'))->toBeFalse();
});

it('never puts an archived migration back when restoring', function () {
    $migration = 'database/migrations/2026_01_01_000000_modsx_blog_posts_table.php';

    $this->makeFile($migration, 'original schema');
    $manager = app(BackupManager::class);
    $manager->backup('Blog');

    // The migration moves on, exactly as a real one would once the schema does.
    File::put($this->root.'/'.$migration, 'schema has moved on');

    $result = $manager->restore('Blog', '0001');

    expect($result)->not->toHaveKey('archived')
        ->and(File::get($this->root.'/'.$migration))->toBe('schema has moved on');
});

it('restores the module files and drops ones the version did not have', function () {
    $this->makeFile('routes/modsx-blog.php', 'v1');

    $manager = app(BackupManager::class);
    $manager->backup('Blog');

    File::put($this->root.'/routes/modsx-blog.php', 'v2');
    $this->makeFile('routes/modsx-blog-admin.php', 'added later');

    $manager->restore('Blog', '0001');

    expect(File::get($this->root.'/routes/modsx-blog.php'))->toBe('v1')
        ->and(File::exists($this->root.'/routes/modsx-blog-admin.php'))->toBeFalse();
});

it('leaves the application alone when the backup turns out to be incomplete', function () {
    $manager = app(BackupManager::class);
    $manager->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'current work');

    // The manifest still lists it, but it is gone from the version.
    File::deleteDirectory($this->root.'/modsx-backups/Blog/0001/resources/views/modsx-blog');

    try {
        $manager->restore('Blog', '0001');
    } catch (ModsxException) {
        // Expected: the point is what the application looks like afterwards.
    }

    expect(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toBe('current work');
});

it('puts the old state back when a restore fails partway through', function () {
    $manager = app(BackupManager::class);
    $backups = app(BackupRepository::class);
    $manager->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'current work');

    // Add a fourth path that copies out of the backup fine but cannot be put
    // into place, because a file sits where its parent directory would go.
    // That fails step 3, after the current state has already been moved aside.
    File::ensureDirectoryExists($this->root.'/modsx-backups/Blog/0001/blocked/ModsxBlog');
    File::put($this->root.'/modsx-backups/Blog/0001/blocked/ModsxBlog/x.php', 'x');
    File::put($this->root.'/blocked', 'a file, not a directory');

    $manifest = $backups->manifest('Blog', '0001');
    $manifest['paths'][] = 'blocked/ModsxBlog';
    $backups->writeManifest($backups->versionPath('Blog', '0001'), $manifest);

    try {
        $manager->restore('Blog', '0001');
    } catch (ModsxException) {
        // Expected.
    }

    // Rolled back: the work that was there before the restore, not the
    // half-restored mixture of old and new the previous design could leave.
    expect(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toBe('current work')
        ->and(File::isDirectory($this->root.'/app/Http/Controllers/ModsxBlog'))->toBeTrue();
});

it('leaves nothing behind in the project root after restoring', function () {
    $manager = app(BackupManager::class);
    $manager->backup('Blog');
    $manager->restore('Blog', '0001');

    $leftovers = array_filter(
        File::directories($this->root),
        static fn (string $path): bool => str_contains(basename($path), '.modsx-tmp-')
    );

    expect($leftovers)->toBeEmpty();
});

it('records an optional comment in the manifest', function () {
    app(BackupManager::class)->backup('Blog', comment: 'before refactor');

    $manifest = app(BackupRepository::class)->manifest('Blog', '0001');

    expect($manifest['comment'])->toBe('before refactor');
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

it('refuses to back up into a tree that differs only in letter case', function () {
    // On Windows and macOS "Blog" and "BLog" are the same directory, so the two
    // modules would share one backup tree: version numbers interleave and a
    // restore hands back the other module's content. Refused on every platform,
    // because behaviour that depends on the filesystem is worse than a
    // consistent no.
    $this->makeBackupVersion('BLog', '0001');

    app(BackupManager::class)->backup('Blog');
})->throws(ModsxException::class);

it('backs up normally when no colliding tree exists', function () {
    $this->makeBackupVersion('Catalog', '0001');

    expect(app(BackupManager::class)->backup('Blog')['version'])->toBe('0001');
});

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

    expect($removed['paths'])->toHaveCount(2)
        ->and(File::isDirectory($this->root.'/resources/views/modsx-blog'))->toBeFalse()
        ->and(File::isDirectory($this->root.'/app/Http/Controllers/ModsxBlog'))->toBeFalse();
});

it('removes a module files along with its directories', function () {
    $this->makeFile('routes/modsx-blog.php');

    $removed = app(BackupManager::class)->delete('Blog');

    expect($removed['files'])->toBe(['routes/modsx-blog.php'])
        ->and(File::exists($this->root.'/routes/modsx-blog.php'))->toBeFalse();
});

it('leaves migrations in place when deleting, and says so', function () {
    // The tables they created are still in the database; removing the file
    // that documents them would leave the schema unexplained.
    $this->makeFile('database/migrations/2026_01_01_000000_modsx_blog_posts_table.php');

    $removed = app(BackupManager::class)->delete('Blog');

    expect($removed['migrations'])->toBe(['database/migrations/2026_01_01_000000_modsx_blog_posts_table.php'])
        ->and(File::exists($this->root.'/database/migrations/2026_01_01_000000_modsx_blog_posts_table.php'))->toBeTrue();
});

it('keeps the newest versions when pruning and never the oldest', function () {
    foreach (['0001', '0002', '0003', '0004', '0005'] as $version) {
        $this->makeBackupVersion('Blog', $version);
    }

    $removed = app(BackupManager::class)->prune('Blog', keep: 2);

    expect($removed)->toBe(['0001', '0002', '0003'])
        ->and(app(BackupRepository::class)->versions('Blog'))->toBe(['0004', '0005']);
});

it('removes a pruned version\'s exported zip along with it', function () {
    $manager = app(BackupManager::class);
    $manager->backup('Blog');
    $manager->export('Blog', '0001');

    $this->makeBackupVersion('Blog', '0002');

    expect(File::exists($this->root.'/modsx-backups/Blog/0001.zip'))->toBeTrue();

    $manager->prune('Blog', keep: 1);

    expect(File::exists($this->root.'/modsx-backups/Blog/0001.zip'))->toBeFalse();
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

it('exports a version to a zip next to it, containing the manifest and every backed-up path', function () {
    $manager = app(BackupManager::class);
    $manager->backup('Blog', comment: 'before refactor');

    $result = $manager->export('Blog');
    $root = str_replace('\\', '/', $this->root);

    expect($result['version'])->toBe('0001')
        ->and($result['path'])->toBe($root.'/modsx-backups/Blog/0001.zip')
        ->and(File::exists($result['path']))->toBeTrue()
        ->and($result['size_bytes'])->toBeGreaterThan(0);

    $zip = new ZipArchive;
    $zip->open($result['path']);

    expect($zip->getFromName('modsx.json'))->not->toBeFalse()
        ->and($zip->locateName('app/Http/Controllers/ModsxBlog/PostController.php'))->not->toBeFalse()
        ->and($zip->locateName('resources/views/modsx-blog/index.blade.php'))->not->toBeFalse();

    $zip->close();
});

it('exports the newest version by default', function () {
    $manager = app(BackupManager::class);
    $manager->backup('Blog');
    $manager->backup('Blog');

    expect($manager->export('Blog')['version'])->toBe('0002');
});

it('fails to export a module with no backups', function () {
    app(BackupManager::class)->export('DoesNotExist');
})->throws(ModsxException::class);

it('fails to export a version that does not exist', function () {
    app(BackupManager::class)->backup('Blog');

    app(BackupManager::class)->export('Blog', '9999');
})->throws(ModsxException::class);

it('leaves no staging file behind after export', function () {
    $manager = app(BackupManager::class);
    $manager->backup('Blog');
    $manager->export('Blog');

    $leftovers = array_filter(
        File::files($this->root.'/modsx-backups/Blog'),
        static fn ($path): bool => str_contains((string) $path, '.tmp-')
    );

    expect($leftovers)->toBeEmpty();
});

it('imports an exported zip back into the backup tree', function () {
    $manager = app(BackupManager::class);
    $manager->backup('Blog', comment: 'before refactor');
    $export = $manager->export('Blog');

    File::deleteDirectory($this->root.'/modsx-backups/Blog/0001');

    $result = $manager->import($export['path']);

    expect($result)->toBe([
        'module' => 'Blog',
        'version' => '0001',
        'target' => str_replace('\\', '/', $this->root).'/modsx-backups/Blog/0001',
    ]);

    $manifest = app(BackupRepository::class)->manifest('Blog', '0001');

    expect($manifest['comment'])->toBe('before refactor')
        ->and(File::isFile($this->root.'/modsx-backups/Blog/0001/app/Http/Controllers/ModsxBlog/PostController.php'))->toBeTrue()
        ->and(File::isFile($this->root.'/modsx-backups/Blog/0001/resources/views/modsx-blog/index.blade.php'))->toBeTrue();
});

it('refuses to import over an existing version', function () {
    $manager = app(BackupManager::class);
    $manager->backup('Blog');
    $export = $manager->export('Blog');

    $manager->import($export['path']);
})->throws(ModsxException::class);

it('fails to import a file that is not a valid export', function () {
    File::put($this->root.'/not-a-zip.txt', 'nope');

    app(BackupManager::class)->import($this->root.'/not-a-zip.txt');
})->throws(ModsxException::class);

it('fails to import a zip missing modsx.json', function () {
    $zip = new ZipArchive;
    $zip->open($this->root.'/empty.zip', ZipArchive::CREATE);
    $zip->addFromString('placeholder.txt', 'x');
    $zip->close();

    app(BackupManager::class)->import($this->root.'/empty.zip');
})->throws(ModsxException::class);

it('leaves no staging directory behind after import', function () {
    $manager = app(BackupManager::class);
    $manager->backup('Blog');
    $export = $manager->export('Blog');
    File::deleteDirectory($this->root.'/modsx-backups/Blog/0001');

    $manager->import($export['path']);

    $leftovers = array_filter(
        File::directories($this->root.'/modsx-backups/Blog'),
        static fn (string $path): bool => str_contains(basename($path), '.modsx-tmp-')
    );

    expect($leftovers)->toBeEmpty();
});
