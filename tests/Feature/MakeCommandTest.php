<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\ModuleLocator;

beforeEach(function () {
    // Laravel's generators read the root namespace out of the project's own
    // composer.json, which a throwaway test root does not otherwise have.
    File::put($this->root.'/composer.json', (string) json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));

    // Two things a real application has and a bare test root does not.
    // make:model writes into app/Models only when that directory exists, and
    // Testbench resolved view.paths before the base path was moved here.
    File::ensureDirectoryExists($this->root.'/app/Models');
    config()->set('view.paths', [$this->root.'/resources/views']);
});

it('generates into the module directory', function () {
    $this->artisan('modsx:make controller Blog/PostController --no-interaction')->assertExitCode(0);

    expect(File::exists($this->root.'/app/Http/Controllers/ModsxBlog/PostController.php'))->toBeTrue();
});

it('puts each name form where Laravel keeps it', function (string $command, string $expected) {
    // End to end, against the real generators: not what we print, but where
    // the file lands. Every form the reference table documents.
    $this->artisan('modsx:make '.$command.' --no-interaction')->assertExitCode(0);

    expect(File::exists($this->root.'/'.$expected))->toBeTrue();
})->with([
    'class into its namespace directory' => ['controller Blog/UserController', 'app/Http/Controllers/ModsxBlog/UserController.php'],
    'model' => ['model Blog/User', 'app/Models/ModsxBlog/User.php'],
    'enum' => ['enum Blog/OrderStatus', 'app/ModsxBlog/OrderStatus.php'],
    'config as a prefixed file' => ['config Blog/services', 'config/modsx-blog-services.php'],

    // The same calls, written with a dot instead.
    'a dot reaches the same class' => ['controller Blog.UserController', 'app/Http/Controllers/ModsxBlog/UserController.php'],
    'a dot reaches the same config' => ['config blog.services', 'config/modsx-blog-services.php'],
]);

it('turns a dotted view name into the directories Laravel reads', function () {
    // make:view does str_replace(['\\', '.'], '/', $name) itself, so the dots
    // in the tail become directories - which is why "modsx-blog/users.index"
    // and "modsx-blog.users.index" are one and the same view.
    $this->artisan('modsx:make view blog.users.index --no-interaction')->assertExitCode(0);

    expect(File::exists($this->root.'/resources/views/modsx-blog/users/index.blade.php'))->toBeTrue();
});

it('puts a layout in the application directory, as its own slice of it', function () {
    // End to end: the file lands beside the application's own layouts, not
    // inside the module's view directory.
    $this->artisan('modsx:make layout blog.app --no-interaction')->assertExitCode(0);

    expect(File::exists($this->root.'/resources/views/layouts/modsx-blog/app.blade.php'))->toBeTrue()
        ->and(File::exists($this->root.'/resources/views/modsx-blog/layouts/app.blade.php'))->toBeFalse();
});

it('puts a page and a partial in theirs', function () {
    $this->artisan('modsx:make page blog.index --no-interaction')->assertExitCode(0);
    $this->artisan('modsx:make partial blog.head --no-interaction')->assertExitCode(0);

    expect(File::exists($this->root.'/resources/views/pages/modsx-blog/index.blade.php'))->toBeTrue()
        ->and(File::exists($this->root.'/resources/views/partials/modsx-blog/head.blade.php'))->toBeTrue();
});

it('still finds a module nested in a shared directory', function () {
    // The whole point of putting it there: it is the module's, and the
    // package sees it as such.
    $this->artisan('modsx:make layout blog.app --no-interaction')->assertExitCode(0);

    expect(app(ModuleLocator::class)->paths('Blog'))->toContain('resources/views/layouts/modsx-blog');
});

it('generates a migration the archive then picks up', function () {
    // A migration is attributed to the longest module name that claims it, and
    // that list comes from the modules present - so the module has to exist
    // for its migration to be found, which in any real order of work it does.
    $this->artisan('modsx:make controller blog.PostController --no-interaction')->assertExitCode(0);
    $this->artisan('modsx:make migration blog.create_users_table --no-interaction')->assertExitCode(0);

    $migrations = File::glob($this->root.'/database/migrations/*_modsx_blog_create_users_table.php');

    expect($migrations)->toHaveCount(1)
        ->and(File::get($migrations[0]))->toContain("Schema::create('users'")
        ->and(app(ModuleLocator::class)->migrations('Blog'))->toHaveCount(1);
});

it('shows the translated command without running it', function () {
    $output = artisanOutput('modsx:make controller Blog/PostController --dry-run --no-interaction');

    expect($output)->toContain('make:controller ModsxBlog/PostController')
        ->and(File::exists($this->root.'/app/Http/Controllers/ModsxBlog/PostController.php'))->toBeFalse();
});

it('adds the table a create migration would otherwise not get', function () {
    expect(artisanOutput('modsx:make migration Blog/create_posts_table --dry-run --no-interaction'))
        ->toContain('modsx_blog_create_posts_table')
        ->toContain('--create=posts');
});

it('produces a create-table migration rather than an empty stub', function () {
    // The whole reason --create is worked out here: with the module prefix in
    // front, Laravel's own guess never fires and the file comes out blank.
    $this->artisan('modsx:make migration Blog/create_posts_table --no-interaction')->assertExitCode(0);

    $migrations = File::glob($this->root.'/database/migrations/*_modsx_blog_create_posts_table.php');

    expect($migrations)->toHaveCount(1)
        ->and(File::get($migrations[0]))->toContain("Schema::create('posts'");
});

it('generates files the package then recognises as the module', function () {
    $this->artisan('modsx:make controller Blog/PostController --no-interaction')->assertExitCode(0);
    $this->artisan('modsx:make migration Blog/create_posts_table --no-interaction')->assertExitCode(0);

    $locator = app(ModuleLocator::class);

    expect($locator->paths('Blog'))->toContain('app/Http/Controllers/ModsxBlog')
        ->and($locator->migrations('Blog'))->toHaveCount(1);
});

it('passes options written after -- to the generator', function () {
    $this->artisan('modsx:make controller Blog/PostController --no-interaction -- --resource')
        ->assertExitCode(0);

    expect(File::get($this->root.'/app/Http/Controllers/ModsxBlog/PostController.php'))
        ->toContain('public function store');
});

it('reports whatever the generator reported', function () {
    $this->artisan('modsx:make controller Blog/PostController --no-interaction')->assertExitCode(0);

    // Laravel's generators refuse to overwrite and still exit 0. We hand that
    // back as it is rather than inventing a failure of our own: whatever
    // make:controller does, this does.
    $this->artisan('modsx:make controller Blog/PostController --no-interaction')
        ->expectsOutputToContain('already exists')
        ->assertExitCode(0);
});

it('fails on a generator that does not exist', function () {
    $this->artisan('modsx:make wombat Blog/Thing --no-interaction')->assertExitCode(1);
});

it('points at modsx:scaffold when given the old syntax', function () {
    expect(artisanOutput('modsx:make Blog --no-interaction'))->toContain('modsx:scaffold');
});

it('explains a name that says nothing about the module', function () {
    expect(artisanOutput('modsx:make controller PostController --no-interaction'))
        ->toContain('backslash');
});

it('warns about an unknown module but still generates when not interactive', function () {
    // Blocking a scripted run would be a regression: creating a file is not
    // destructive, and this is the one command in the package that never is.
    $this->artisan('modsx:make controller Blogg/PostController --no-interaction')->assertExitCode(0);

    expect(File::exists($this->root.'/app/Http/Controllers/ModsxBlogg/PostController.php'))->toBeTrue();
});

it('suggests the module you probably meant', function () {
    $this->makeModuleDirectory('app/Models/ModsxBlog');

    expect(artisanOutput('modsx:make controller Blogg/PostController --no-interaction'))
        ->toContain('Did you mean');
});

it('says nothing about a module that exists', function () {
    $this->makeModuleDirectory('app/Models/ModsxBlog');

    expect(artisanOutput('modsx:make controller Blog/PostController --no-interaction'))
        ->not->toContain('does not exist yet');
});

it('treats a module that lives only in the backups as known', function () {
    // Between modsx:delete and modsx:restore there is nothing on disk to find.
    $this->makeBackupVersion('Blog', '0001');

    expect(artisanOutput('modsx:make controller Blog/PostController --no-interaction'))
        ->not->toContain('does not exist yet');
});

it('changes nothing when you decline an unknown module', function () {
    $this->artisan('modsx:make controller Blogg/PostController')
        ->expectsConfirmation('Create [Blogg]?', 'no')
        ->assertExitCode(0);

    expect(File::exists($this->root.'/app/Http/Controllers/ModsxBlogg/PostController.php'))->toBeFalse();
});

it('warns that a model migration lands outside the module', function () {
    // make:model -m names the migration itself, so it carries no module prefix
    // and modsx will never back it up with the module.
    expect(artisanOutput('modsx:make model Blog/Post --no-interaction -- -m'))
        ->toContain('modsx:doctor');
});

it('says nothing about migrations when the model comes on its own', function () {
    expect(artisanOutput('modsx:make model Blog/Post --no-interaction'))
        ->not->toContain('modsx:doctor');
});
