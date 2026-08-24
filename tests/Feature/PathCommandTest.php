<?php

declare(strict_types=1);

beforeEach(function () {
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('shows the directories that make up a named module', function () {
    $output = json_decode(artisanOutput('modsx:path Blog --json'), true);

    expect($output)->toBe([
        'Blog' => [
            'app/Http/Controllers/ModsxBlog',
            'resources/views/modsx-blog',
        ],
    ]);
});

it('shows every module when no name is given', function () {
    $this->makeModuleDirectory('resources/views/modsx-shop');

    $output = json_decode(artisanOutput('modsx:path --json'), true);

    expect(array_keys($output))->toBe(['Blog', 'Shop']);
});

it('fails when the named module is not in the application', function () {
    $this->artisan('modsx:path Ghost --json')->assertExitCode(1);
});

it('rejects an invalid module name', function () {
    $this->artisan('modsx:path', ['name' => '../etc'])->assertExitCode(1);
});
