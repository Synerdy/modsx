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

it('reads a file name exactly, the way it reads a directory name', function () {
    // A suffix makes a different name, and a different name is a different
    // module - "modsx-blog-admin" names BlogAdmin whether it is a directory or
    // a file. That is what stops two modules ever claiming the same file.
    $this->makeFile('routes/modsx-blog-admin.php');
    $this->makeFile('routes/modsx-blogging.php');
    $this->makeFile('routes/blog-modsx.php');

    expect(app(ModuleLocator::class)->files('Blog'))->toBe([]);
});

it('keeps every extension, cutting the name at the first dot', function () {
    $this->makeFile('resources/views/modsx-blog.blade.php');
    $this->makeFile('public/modsx-blog.css');
    $this->makeFile('lang/pl/modsx-blog.php');

    expect(app(ModuleLocator::class)->files('Blog'))->toBe([
        'lang/pl/modsx-blog.php',
        'public/modsx-blog.css',
        'resources/views/modsx-blog.blade.php',
    ]);
});

it('gives a suffixed file to the module it actually names', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog-post', 'index.blade.php', 'v1');
    $this->makeFile('config/modsx-blog.php');
    $this->makeFile('config/modsx-blog-post.php');

    $locator = app(ModuleLocator::class);

    expect($locator->files('Blog'))->toBe(['config/modsx-blog.php'])
        ->and($locator->files('BlogPost'))->toBe(['config/modsx-blog-post.php']);
});

it('leaves a single file in the Studly form to nobody', function () {
    // The Studly form is for the namespace directories a class lives in.
    $this->makeFile('app/Support/ModsxBlog.php');

    expect(app(ModuleLocator::class)->files('Blog'))->toBe([]);
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

it('gives a migration to the longest module name that claims it', function () {
    // A migration is the one thing that cannot be named for its module and
    // nothing else, so the module list draws the boundary: "post" continues
    // BlogPost's name, while Blog's own migration would say "create" here.
    $this->makeModuleDirectory('resources/views/modsx-blog-post', 'index.blade.php', 'v1');
    $this->makeFile('database/migrations/2026_01_01_000000_modsx_blog_create_posts_table.php');
    $this->makeFile('database/migrations/2026_01_02_000000_modsx_blog_post_create_comments_table.php');

    $locator = app(ModuleLocator::class);

    expect($locator->migrations('Blog'))
        ->toBe(['database/migrations/2026_01_01_000000_modsx_blog_create_posts_table.php'])
        ->and($locator->migrations('BlogPost'))
        ->toBe(['database/migrations/2026_01_02_000000_modsx_blog_post_create_comments_table.php']);
});

it('gives the same migration to the shorter module when the longer one does not exist', function () {
    $this->makeFile('database/migrations/2026_01_01_000000_modsx_blog_post_create_comments_table.php');

    expect(app(ModuleLocator::class)->migrations('Blog'))
        ->toBe(['database/migrations/2026_01_01_000000_modsx_blog_post_create_comments_table.php']);
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
