<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;

beforeEach(function () {
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('warns when no modules are found', function () {
    File::deleteDirectory($this->root.'/app/Http/Controllers/ModsxBlog');
    File::deleteDirectory($this->root.'/resources/views/modsx-blog');

    $output = artisanOutput('modsx:list');

    expect($output)->toContain('No modules found.');
});

it('lists the modules present in the application', function () {
    $output = artisanOutput('modsx:list');

    expect($output)->toContain('Blog')
        ->and($output)->toContain('2'); // two directories
});

it('emits the same data as json', function () {
    $output = json_decode(artisanOutput('modsx:list --json'), true);

    expect($output)->toBe([
        'Blog' => [
            'app/Http/Controllers/ModsxBlog',
            'resources/views/modsx-blog',
        ],
    ]);
});

it('includes backup counts once a module has been backed up', function () {
    app(BackupManager::class)->backup('Blog');

    $output = artisanOutput('modsx:list');

    expect($output)->toContain('0001');
});
