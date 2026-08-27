<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupRepository;

beforeEach(function () {
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('copies a module into a new backup version', function () {
    $this->artisan('modsx:backup Blog')->assertExitCode(0);

    expect(File::isDirectory($this->root.'/modsx-backups/Blog/0001/app/Http/Controllers/ModsxBlog'))->toBeTrue()
        ->and(File::isDirectory($this->root.'/modsx-backups/Blog/0001/resources/views/modsx-blog'))->toBeTrue();
});

it('reports the version it created', function () {
    $output = artisanOutput('modsx:backup Blog');

    expect($output)->toContain('Backed up [Blog] as version 0001');
});

it('fails when the module is not in the application', function () {
    $this->artisan('modsx:backup DoesNotExist')->assertExitCode(1);

    expect(File::isDirectory($this->root.'/modsx-backups/DoesNotExist'))->toBeFalse();
});

it('suppresses the banner when asked, for use from other commands', function () {
    $output = artisanOutput('modsx:backup Blog --quiet-banner');

    expect($output)->not->toContain('█');
});

it('stores a comment given via -m', function () {
    $this->artisan('modsx:backup Blog -m "before refactor"')->assertExitCode(0);

    expect(app(BackupRepository::class)->manifest('Blog', '0001')['comment'])->toBe('before refactor');
});

it('leaves the comment null when none is given', function () {
    $this->artisan('modsx:backup Blog')->assertExitCode(0);

    expect(app(BackupRepository::class)->manifest('Blog', '0001')['comment'])->toBeNull();
});

it('reports the created version as json', function () {
    $output = json_decode(artisanOutput('modsx:backup Blog --json'), true);

    expect($output['Blog']['version'])->toBe('0001')
        ->and($output['Blog']['skipped'])->toBeFalse();
});

it('backs up every module with --all', function () {
    $this->makeModuleDirectory('resources/views/modsx-shop', 'index.blade.php', 'v1');

    $output = json_decode(artisanOutput('modsx:backup --all --json'), true);

    expect(array_keys($output))->toBe(['Blog', 'Shop'])
        ->and(File::isDirectory($this->root.'/modsx-backups/Blog/0001'))->toBeTrue()
        ->and(File::isDirectory($this->root.'/modsx-backups/Shop/0001'))->toBeTrue();
});

it('takes no second copy when nothing changed', function () {
    $this->artisan('modsx:backup Blog')->assertExitCode(0);

    $output = json_decode(artisanOutput('modsx:backup Blog --skip-unchanged --json'), true);

    expect($output['Blog']['skipped'])->toBeTrue()
        ->and($output['Blog']['version'])->toBe('0001')
        ->and(app(BackupRepository::class)->versions('Blog'))->toBe(['0001']);
});

it('still copies when something did change', function () {
    $this->artisan('modsx:backup Blog')->assertExitCode(0);

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');

    $output = json_decode(artisanOutput('modsx:backup Blog --skip-unchanged --json'), true);

    expect($output['Blog']['skipped'])->toBeFalse()
        ->and($output['Blog']['version'])->toBe('0002');
});

it('does not count an archived migration as a change', function () {
    // Migrations are not part of the state a restore puts back, so a change to
    // one is no reason to take another copy of everything else.
    $this->makeFile('database/migrations/2026_01_01_000000_modsx_blog_posts_table.php', 'schema');
    $this->artisan('modsx:backup Blog')->assertExitCode(0);

    File::put($this->root.'/database/migrations/2026_01_01_000000_modsx_blog_posts_table.php', 'schema v2');

    $output = json_decode(artisanOutput('modsx:backup Blog --skip-unchanged --json'), true);

    expect($output['Blog']['skipped'])->toBeTrue();
});
