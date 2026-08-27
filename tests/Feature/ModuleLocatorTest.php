<?php

declare(strict_types=1);

use Modsx\ModuleLocator;

beforeEach(function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('finds the single files belonging to a module', function () {
    $this->makeFile('routes/modsx-blog.php');
    $this->makeFile('config/modsx-blog.php');
    $this->makeFile('lang/en/modsx-blog.php');

    expect(app(ModuleLocator::class)->files('Blog'))->toBe([
        'config/modsx-blog.php',
        'lang/en/modsx-blog.php',
        'routes/modsx-blog.php',
    ]);
});

it('claims the whole prefix, but only up to a word boundary', function () {
    $this->makeFile('routes/modsx-blog-admin.php');   // Blog's: boundary is "-"
    $this->makeFile('routes/modsx-blogging.php');     // not Blog's: no boundary

    expect(app(ModuleLocator::class)->files('Blog'))->toBe(['routes/modsx-blog-admin.php']);
});

it('does not list files that live inside the module own directories', function () {
    // Already copied wholesale with the directory; listing it again would back
    // the same file up twice.
    $this->makeFile('resources/views/modsx-blog/modsx-blog.blade.php');

    expect(app(ModuleLocator::class)->files('Blog'))->toBe([]);
});

it('does not treat the backup tree as application files', function () {
    $this->makeFile('modsx-backups/Blog/0001/routes/modsx-blog.php');

    expect(app(ModuleLocator::class)->files('Blog'))->toBe([]);
});

it('finds migrations named per the convention', function () {
    $this->makeFile('database/migrations/2026_01_01_000000_modsx_blog_posts_table.php');
    $this->makeFile('database/migrations/2026_01_02_000000_modsx_blog.php');

    expect(app(ModuleLocator::class)->migrations('Blog'))->toBe([
        'database/migrations/2026_01_01_000000_modsx_blog_posts_table.php',
        'database/migrations/2026_01_02_000000_modsx_blog.php',
    ]);
});

it('ignores migrations that mention the module but are not named per the convention', function () {
    // The classic Laravel ordering, verb first. modsx:doctor reports these
    // separately and suggests the rename; discovery deliberately does not guess.
    $this->makeFile('database/migrations/2026_01_01_000000_create_modsx_blog_posts_table.php');

    expect(app(ModuleLocator::class)->migrations('Blog'))->toBe([]);
});

it('does not match a longer module name that merely starts the same', function () {
    $this->makeFile('database/migrations/2026_01_01_000000_modsx_blogging_table.php');

    expect(app(ModuleLocator::class)->migrations('Blog'))->toBe([]);
});

it('gives the prefix owner everything under it', function () {
    // Blog owns modsx_blog*, so this is Blog's even though a module named
    // BlogPost would read it as its own. Having both modules at once is a
    // naming conflict, which modsx:doctor reports.
    $this->makeFile('database/migrations/2026_01_01_000000_modsx_blog_post_comments_table.php');

    expect(app(ModuleLocator::class)->migrations('Blog'))
        ->toBe(['database/migrations/2026_01_01_000000_modsx_blog_post_comments_table.php']);
});

it('does not recurse into migration subdirectories, matching Laravel', function () {
    $this->makeFile('database/migrations/nested/2026_01_01_000000_modsx_blog_posts_table.php');

    expect(app(ModuleLocator::class)->migrations('Blog'))->toBe([]);
});
