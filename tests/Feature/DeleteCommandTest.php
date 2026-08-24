<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupRepository;

beforeEach(function () {
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('backs up before removing the module', function () {
    $this->artisan('modsx:delete Blog --force')->assertExitCode(0);

    expect(File::isDirectory($this->root.'/app/Http/Controllers/ModsxBlog'))->toBeFalse()
        ->and(File::isDirectory($this->root.'/resources/views/modsx-blog'))->toBeFalse()
        ->and(app(BackupRepository::class)->versions('Blog'))->toBe(['0001']);
});

it('skips the backup when told to', function () {
    $this->artisan('modsx:delete Blog --force --skip-backup')->assertExitCode(0);

    expect(File::isDirectory($this->root.'/app/Http/Controllers/ModsxBlog'))->toBeFalse()
        ->and(app(BackupRepository::class)->versions('Blog'))->toBe([]);
});

it('changes nothing without --force in non-interactive mode', function () {
    $this->artisan('modsx:delete Blog --no-interaction')->assertExitCode(0);

    expect(File::isDirectory($this->root.'/app/Http/Controllers/ModsxBlog'))->toBeTrue()
        ->and(app(BackupRepository::class)->versions('Blog'))->toBe([]);
});

it('fails when the module is not in the application', function () {
    $this->artisan('modsx:delete DoesNotExist --force')->assertExitCode(1);
});
