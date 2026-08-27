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
});

it('generates into the module directory', function () {
    $this->artisan('modsx:make controller Blog/PostController --no-interaction')->assertExitCode(0);

    expect(File::exists($this->root.'/app/Http/Controllers/ModsxBlog/PostController.php'))->toBeTrue();
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
