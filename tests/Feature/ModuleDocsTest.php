<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;
use Modsx\ModuleLocator;

beforeEach(function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

it('treats a page named for the module as the module\'s own', function () {
    $this->makeFile('docs/modsx-blog.md', '# Blog');

    expect(app(ModuleLocator::class)->files('Blog'))->toContain('docs/modsx-blog.md');
});

it('does not hand one module a page named for another', function () {
    // The same boundary that decides every other file: modsx-blog is the start
    // of modsx-blog-post, and only the whole name counts.
    $this->makeModuleDirectory('resources/views/modsx-blog-post', 'index.blade.php', 'v1');
    $this->makeFile('docs/modsx-blog-post.md', '# BlogPost');

    expect(app(ModuleLocator::class)->files('Blog'))->not->toContain('docs/modsx-blog-post.md')
        ->and(app(ModuleLocator::class)->files('BlogPost'))->toContain('docs/modsx-blog-post.md');
});

it('leaves a page belonging to no module alone', function () {
    $this->makeFile('docs/architecture.md', 'about the whole application');

    expect(app(ModuleLocator::class)->files('Blog'))->not->toContain('docs/architecture.md');
});

it('takes a directory of pages when one page is not enough', function () {
    $this->makeModuleDirectory('docs/modsx-blog', 'installation.md', '# Installing');
    $this->makeFile('docs/modsx-blog/api.md', '# API');

    expect(app(ModuleLocator::class)->paths('Blog'))->toContain('docs/modsx-blog');
});

it('lets a directory of pages make a module on its own', function () {
    // Worth knowing rather than worth preventing: a module is a set of
    // directories, and docs/modsx-shop is one. Documentation written before
    // any code therefore brings the module into being, while a lone
    // docs/modsx-shop.md does not - the same rule as everywhere else.
    $this->makeModuleDirectory('docs/modsx-shop', 'index.md', '# Shop');

    expect(app(ModuleLocator::class)->names())->toContain('Shop');
});

it('carries documentation into the backup and back out again', function () {
    // The point of documentation being module content: restoring a module from
    // three weeks ago gives you the documentation it had three weeks ago.
    $this->makeFile('docs/modsx-blog.md', '# Blog, first draft');

    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/docs/modsx-blog.md', '# Blog, rewritten');
    app(BackupManager::class)->backup('Blog');

    app(BackupManager::class)->restore('Blog', '0001');

    expect(File::get($this->root.'/docs/modsx-blog.md'))->toBe('# Blog, first draft');
});

it('says where the documentation is', function () {
    $this->makeFile('docs/modsx-blog.md', '# Blog');

    expect(json_decode(artisanOutput('modsx:info Blog --json'), true)['documentation'])
        ->toBe(['docs/modsx-blog.md']);
});

it('says nothing about documentation when there is none', function () {
    expect(json_decode(artisanOutput('modsx:info Blog --json'), true)['documentation'])->toBe([]);
});

it('says nothing about documentation when docs is not scanned', function () {
    // No second setting to disagree with scan_paths: take docs out of it and
    // the pages stop being module content, so nothing claims otherwise.
    $this->makeFile('docs/modsx-blog.md', '# Blog');
    config()->set('modsx.scan_paths', ['app', 'resources']);

    expect(json_decode(artisanOutput('modsx:info Blog --json'), true)['documentation'])->toBe([]);
});
