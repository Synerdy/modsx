<?php

declare(strict_types=1);

use Modsx\Exceptions\ModsxException;
use Modsx\ModuleMaker;

it('writes a class into the module directory', function () {
    $target = app(ModuleMaker::class)->resolve('controller', 'Blog/PostController');

    expect($target['name'])->toBe('ModsxBlog/PostController')
        ->and($target['module']->studly)->toBe('Blog')
        ->and($target['options'])->toBe([]);
});

it('writes a view into the kebab-case directory', function () {
    expect(app(ModuleMaker::class)->resolve('view', 'UserProfile/index')['name'])
        ->toBe('modsx-user-profile/index');
});

it('writes a config file under the module file prefix, not in a directory', function () {
    // config/modsx-blog-settings.php is Blog's by the same rule the backup
    // uses: the prefix, then a word boundary.
    expect(app(ModuleMaker::class)->resolve('config', 'Blog/settings')['name'])
        ->toBe('modsx-blog-settings');
});

it('writes a migration under the snake_case module prefix', function () {
    expect(app(ModuleMaker::class)->resolve('migration', 'Blog/create_posts_table')['name'])
        ->toBe('modsx_blog_create_posts_table');
});

it('keeps sub-directories below the module', function () {
    expect(app(ModuleMaker::class)->resolve('controller', 'Blog/Admin/PostController')['name'])
        ->toBe('ModsxBlog/Admin/PostController');
});

it('accepts a backslash as well as a slash', function () {
    // PowerShell escapes with a backtick, so a backslash arrives intact there.
    expect(app(ModuleMaker::class)->resolve('controller', 'Blog\\PostController')['name'])
        ->toBe('ModsxBlog/PostController');
});

it('falls back to the studly directory for a generator it has never heard of', function () {
    expect(app(ModuleMaker::class)->resolve('livewire', 'Blog/PostList')['name'])
        ->toBe('ModsxBlog/PostList');
});

it('follows the configured map rather than a fixed layout', function () {
    config()->set('modsx.generators', ['*' => '{kebab}/']);

    expect(app(ModuleMaker::class)->resolve('controller', 'Blog/PostController')['name'])
        ->toBe('modsx-blog/PostController');
});

it('uses the configured prefix', function () {
    config()->set('modsx.prefix', 'mx');

    expect(app(ModuleMaker::class)->resolve('controller', 'Blog/PostController')['name'])
        ->toBe('MxBlog/PostController');
});

it('names the table a create migration would otherwise not get', function () {
    // Laravel guesses the table from the whole migration name, and the module
    // prefix in front defeats the guess - so without this the file comes out
    // as an empty stub instead of a Schema::create.
    expect(app(ModuleMaker::class)->resolve('migration', 'Blog/create_posts_table')['options'])
        ->toBe(['--create=posts']);
});

it('names the table a change migration works on', function () {
    expect(app(ModuleMaker::class)->resolve('migration', 'Blog/add_slug_to_posts_table')['options'])
        ->toBe(['--table=posts']);
});

it('leaves an explicit --create alone', function () {
    expect(app(ModuleMaker::class)->resolve('migration', 'Blog/create_posts_table', ['--create=articles'])['options'])
        ->toBe(['--create=articles']);
});

it('guesses no table when the name does not name one', function () {
    expect(app(ModuleMaker::class)->resolve('migration', 'Blog/tidy_up')['options'])->toBe([]);
});

it('normalises a migration name the way Laravel does', function () {
    expect(app(ModuleMaker::class)->resolve('migration', 'Blog/CreatePostsTable')['name'])
        ->toBe('modsx_blog_create_posts_table');
});

it('passes the generator options through untouched', function () {
    expect(app(ModuleMaker::class)->resolve('controller', 'Blog/PostController', ['--resource', '-m'])['options'])
        ->toBe(['--resource', '-m']);
});

it('rejects a name that says nothing about the module', function () {
    expect(fn () => app(ModuleMaker::class)->resolve('controller', 'PostController'))
        ->toThrow(ModsxException::class, 'does not say which module');
});

it('names the shell trap, because by then the separator is gone', function () {
    // "Blog\PostController" unquoted in a POSIX shell arrives as
    // "BlogPostController": there is nothing left to detect but this message.
    expect(fn () => app(ModuleMaker::class)->resolve('controller', 'BlogPostController'))
        ->toThrow(ModsxException::class, 'backslash');
});

it('rejects a module name that could escape the project', function () {
    expect(fn () => app(ModuleMaker::class)->resolve('controller', '../etc/passwd'))
        ->toThrow(ModsxException::class);
});

it('rejects a module with nothing after it', function () {
    expect(fn () => app(ModuleMaker::class)->resolve('controller', 'Blog/'))
        ->toThrow(ModsxException::class, 'does not say which module');
});
