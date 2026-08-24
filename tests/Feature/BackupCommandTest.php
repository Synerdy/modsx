<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('copies a module into a new backup version', function () {
    $this->artisan('modules:backup Blog')->assertExitCode(0);

    expect(File::isDirectory($this->root.'/ModulesX/Blog/0001/app/Http/Controllers/ModsxBlog'))->toBeTrue()
        ->and(File::isDirectory($this->root.'/ModulesX/Blog/0001/resources/views/modsx-blog'))->toBeTrue();
});

it('reports the version it created', function () {
    $output = artisanOutput('modules:backup Blog');

    expect($output)->toContain('Backed up [Blog] as version 0001');
});

it('fails when the module is not in the application', function () {
    $this->artisan('modules:backup DoesNotExist')->assertExitCode(1);

    expect(File::isDirectory($this->root.'/ModulesX/DoesNotExist'))->toBeFalse();
});

it('suppresses the banner when asked, for use from other commands', function () {
    $output = artisanOutput('modules:backup Blog --quiet-banner');

    expect($output)->not->toContain('__');
});
