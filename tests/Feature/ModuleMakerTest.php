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

    // The pattern settles the whole name, not just the module in front of it.
    expect(app(ModuleMaker::class)->resolve('controller', 'Blog/PostController')['name'])
        ->toBe('modsx-blog/post-controller');
});

it('writes the rest of the name in the same form as the module', function () {
    // Half a name converted and half left alone is what nobody wants:
    // "modsx-blog-MailSettings" is neither a key you would type nor one
    // Laravel would generate.
    $maker = app(ModuleMaker::class);

    expect($maker->resolve('view', 'Blog/PostList')['name'])->toBe('modsx-blog/post-list')
        ->and($maker->resolve('config', 'Blog/MailSettings')['name'])->toBe('modsx-blog-mail-settings');
});

it('converts a nested name segment by segment', function () {
    // Str::kebab('Admin/PostList') is 'admin/-post-list': the separator reads
    // as a word boundary, so each segment has to be converted on its own.
    expect(app(ModuleMaker::class)->resolve('view', 'Blog/Admin/PostList')['name'])
        ->toBe('modsx-blog/admin/post-list');
});

it('leaves a dotted view name intact', function () {
    expect(app(ModuleMaker::class)->resolve('view', 'Blog/admin.index')['name'])
        ->toBe('modsx-blog/admin.index');
});

it('leaves a class name alone', function () {
    // The Studly form is what the generator wants already.
    expect(app(ModuleMaker::class)->resolve('controller', 'Blog/Admin/PostController')['name'])
        ->toBe('ModsxBlog/Admin/PostController');
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
