<?php

declare(strict_types=1);

beforeEach(function () {
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('shows everything that makes up a named module', function () {
    $this->makeFile('routes/modsx-blog.php');
    $this->makeFile('database/migrations/2026_01_01_000000_modsx_blog_posts_table.php');

    $output = json_decode(artisanOutput('modsx:path Blog --json'), true);

    // This command is documented as showing exactly what a backup would copy,
    // so leaving files or migrations out of it would make it lie.
    expect($output)->toBe([
        'Blog' => [
            'directories' => [
                'app/Http/Controllers/ModsxBlog',
                'resources/views/modsx-blog',
            ],
            'files' => ['routes/modsx-blog.php'],
            'migrations' => ['database/migrations/2026_01_01_000000_modsx_blog_posts_table.php'],
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
