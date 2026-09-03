<?php

declare(strict_types=1);

use Modsx\ModuleDependencies;

function needs(string $module): array
{
    return array_column(app(ModuleDependencies::class)->for($module), 'module');
}

it('finds a dependency written as a namespace', function () {
    $this->makeModuleDirectory('resources/views/modsx-user', 'index.blade.php', 'x');
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php',
        '<?php use App\Models\ModsxUser\Account;');

    expect(needs('Blog'))->toBe(['User']);
});

it('finds a dependency written as a view name', function () {
    $this->makeModuleDirectory('resources/views/modsx-media', 'player.blade.php', 'x');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php',
        "@include('modsx-media.player')");

    expect(needs('Blog'))->toBe(['Media']);
});

it('finds a dependency written as a table prefix', function () {
    $this->makeModuleDirectory('resources/views/modsx-media', 'player.blade.php', 'x');
    $this->makeModuleDirectory('app/Models/ModsxBlog', 'Post.php',
        '<?php $table = "modsx_media_assets";');

    expect(needs('Blog'))->toBe(['Media']);
});

it('does not read a longer module name as a shorter one', function () {
    // The trap from 0.5.0 in another guise: "ModsxBlog" is the start of
    // "ModsxBlogPost", so without a boundary every reference to BlogPost would
    // register as a reference to Blog as well.
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'x');
    $this->makeModuleDirectory('resources/views/modsx-blog-post', 'index.blade.php', 'x');
    $this->makeModuleDirectory('app/Http/Controllers/ModsxShop', 'Controller.php',
        '<?php use App\Models\ModsxBlogPost\Comment;');

    expect(needs('Shop'))->toBe(['BlogPost']);
});

it('reads the shorter name when that is what is written', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'x');
    $this->makeModuleDirectory('resources/views/modsx-blog-post', 'index.blade.php', 'x');
    $this->makeModuleDirectory('app/Http/Controllers/ModsxShop', 'Controller.php',
        '<?php use App\Models\ModsxBlog\Post;');

    expect(needs('Shop'))->toBe(['Blog']);
});

it('never makes a module depend on itself', function () {
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php',
        '<?php namespace App\Http\Controllers\ModsxBlog;');

    expect(needs('Blog'))->toBe([]);
});

it('adds an edge declared in configuration', function () {
    $this->makeModuleDirectory('resources/views/modsx-search', 'index.blade.php', 'x');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'nothing in here');

    config()->set('modsx.dependencies', ['Blog' => ['Search']]);

    expect(app(ModuleDependencies::class)->for('Blog'))
        ->toBe([['module' => 'Search', 'via' => 'config']]);
});

it('calls an edge found in the code found, even when it is also declared', function () {
    // The stronger of the two claims wins the label: one can be pointed at in
    // a file, the other is somebody's word.
    $this->makeModuleDirectory('resources/views/modsx-user', 'index.blade.php', 'x');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', "@include('modsx-user.card')");

    config()->set('modsx.dependencies', ['Blog' => ['User']]);

    expect(app(ModuleDependencies::class)->for('Blog'))
        ->toBe([['module' => 'User', 'via' => 'scan']]);
});

it('ignores a declared module that does not exist', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'x');

    config()->set('modsx.dependencies', ['Blog' => ['Ghost']]);

    expect(needs('Blog'))->toBe([]);
});

it('follows dependencies through to everything they need', function () {
    $this->makeModuleDirectory('resources/views/modsx-media', 'player.blade.php', 'x');
    $this->makeModuleDirectory('resources/views/modsx-user', 'card.blade.php', "@include('modsx-media.player')");
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', "@include('modsx-user.card')");

    expect(app(ModuleDependencies::class)->closure('Blog'))->toBe(['Blog', 'User', 'Media']);
});

it('does not loop on modules that need each other', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', "@include('modsx-user.card')");
    $this->makeModuleDirectory('resources/views/modsx-user', 'card.blade.php', "@include('modsx-blog.index')");

    expect(app(ModuleDependencies::class)->closure('Blog'))->toBe(['Blog', 'User']);
});

it('reports modules that depend on one another', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', "@include('modsx-user.card')");
    $this->makeModuleDirectory('resources/views/modsx-user', 'card.blade.php', "@include('modsx-blog.index')");

    $cycles = app(ModuleDependencies::class)->cycles();

    expect($cycles)->toHaveCount(1)
        ->and($cycles[0])->toEqualCanonicalizing(['Blog', 'User']);
});

it('reports nothing when the graph has no rings in it', function () {
    $this->makeModuleDirectory('resources/views/modsx-user', 'card.blade.php', 'x');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', "@include('modsx-user.card')");

    expect(app(ModuleDependencies::class)->cycles())->toBe([]);
});

it('does not read a binary file for module names', function () {
    $this->makeModuleDirectory('resources/views/modsx-media', 'player.blade.php', 'x');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'bundle.bin', "\0\0ModsxMedia\0\0");

    expect(needs('Blog'))->toBe([]);
});

it('marks both modules when a table name contains the shorter one', function () {
    // Documented in the README: the snake form allows a suffix, so this string
    // is a reference to Media and to MediaAssets at once. Over-inclusion is the
    // safe direction - it costs a directory in a snapshot, not a broken restore.
    $this->makeModuleDirectory('resources/views/modsx-media', 'p.blade.php', 'x');
    $this->makeModuleDirectory('resources/views/modsx-media-assets', 'r.blade.php', 'x');
    $this->makeModuleDirectory('app/Models/ModsxBlog', 'Post.php',
        '<?php $table = "modsx_media_assets";');

    expect(needs('Blog'))->toBe(['Media', 'MediaAssets']);
});

it('keeps a hyphenated view name to the longer module only', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'i.blade.php', 'x');
    $this->makeModuleDirectory('resources/views/modsx-blog-post', 'c.blade.php', 'x');
    $this->makeModuleDirectory('app/Http/Controllers/ModsxShop', 'C.php',
        "<?php view('modsx-blog-post.comment');");

    expect(needs('Shop'))->toBe(['BlogPost']);
});
