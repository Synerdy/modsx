<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\ModuleLocator;

it('creates both directory forms from one name', function () {
    $this->artisan('modsx:scaffold Blog')->assertExitCode(0);

    expect(File::isDirectory($this->root.'/app/Http/Controllers/ModsxBlog'))->toBeTrue()
        ->and(File::isDirectory($this->root.'/app/Models/ModsxBlog'))->toBeTrue()
        ->and(File::isDirectory($this->root.'/resources/views/modsx-blog'))->toBeTrue();
});

it('derives both forms from the same name, whatever case is typed', function () {
    // The trap this command exists to close: hand-writing "modsx-userprofile"
    // next to "ModsxUserProfile" makes two modules that read as one.
    $this->artisan('modsx:scaffold user-profile')->assertExitCode(0);

    expect(File::isDirectory($this->root.'/app/Models/ModsxUserProfile'))->toBeTrue()
        ->and(File::isDirectory($this->root.'/resources/views/modsx-user-profile'))->toBeTrue();
});

it('produces a module the locator then finds', function () {
    $this->artisan('modsx:scaffold Blog')->assertExitCode(0);

    // Empty directories are still directories, so discovery sees them.
    expect(app(ModuleLocator::class)->names())->toBe(['Blog']);
});

it('skips directories that already exist instead of failing', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'keep me');

    $output = json_decode(artisanOutput('modsx:scaffold Blog --json'), true);

    expect($output['skipped'])->toContain('resources/views/modsx-blog')
        ->and($output['created'])->toContain('app/Models/ModsxBlog')
        ->and(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toBe('keep me');
});

it('follows the configured scaffold rather than a fixed layout', function () {
    config()->set('modsx.scaffold', ['app/Livewire/{Studly}', 'resources/css/{kebab}']);

    $output = json_decode(artisanOutput('modsx:scaffold Blog --json'), true);

    expect($output['created'])->toBe(['app/Livewire/ModsxBlog', 'resources/css/modsx-blog']);
});

it('creates the nested layouts the config offers', function () {
    // The commented-out entries in config/modsx.php. Views take the same shape
    // as everything else - the framework's directory first, the module inside
    // it - which is what makes <x-modsx-blog.card> resolve.
    config()->set('modsx.scaffold', [
        'resources/views/components/{kebab}',
        'resources/views/layouts/{kebab}',
        'resources/css/{kebab}',
        'app/Livewire/{Studly}',
    ]);

    $output = json_decode(artisanOutput('modsx:scaffold Blog --json'), true);

    expect($output['created'])->toBe([
        'resources/views/components/modsx-blog',
        'resources/views/layouts/modsx-blog',
        'resources/css/modsx-blog',
        'app/Livewire/ModsxBlog',
    ])
        ->and(File::isDirectory($this->root.'/resources/views/components/modsx-blog'))->toBeTrue()
        ->and(File::isDirectory($this->root.'/resources/views/layouts/modsx-blog'))->toBeTrue();
});

it('creates just the directories named, not the configured list', function () {
    $output = json_decode(artisanOutput('modsx:scaffold Blog resources/css resources/js --json'), true);

    expect($output['created'])->toBe(['resources/css/modsx-blog', 'resources/js/modsx-blog'])
        ->and(File::isDirectory($this->root.'/app/Models/ModsxBlog'))->toBeFalse();
});

it('reads the name form off the directory the path leads to', function (string $path, string $expected) {
    // app/, database/ and tests/ are PSR-4 namespace roots, where a hyphen is
    // not a legal PHP identifier; everywhere else the name is only a path.
    $output = json_decode(artisanOutput('modsx:scaffold Blog '.$path.' --json'), true);

    expect($output['created'])->toBe([$expected]);
})->with([
    'assets are a path' => ['resources/css', 'resources/css/modsx-blog'],
    'so is public' => ['public/vendor', 'public/vendor/modsx-blog'],
    'app is a namespace' => ['app/Services', 'app/Services/ModsxBlog'],
    'so is database' => ['database/factories', 'database/factories/ModsxBlog'],
    'and tests' => ['tests/Feature', 'tests/Feature/ModsxBlog'],
]);

it('lets a placeholder settle the form itself', function () {
    // For the layouts inference cannot know about - a PSR-4 root of your own.
    $output = json_decode(artisanOutput('modsx:scaffold Blog docs/{Studly} --json'), true);

    expect($output['created'])->toBe(['docs/ModsxBlog']);
});

it('skips a named directory that already exists', function () {
    $this->makeModuleDirectory('resources/css/modsx-blog', 'blog.css', 'v1');

    $output = json_decode(artisanOutput('modsx:scaffold Blog resources/css --json'), true);

    expect($output['created'])->toBe([])
        ->and($output['skipped'])->toBe(['resources/css/modsx-blog'])
        ->and(File::get($this->root.'/resources/css/modsx-blog/blog.css'))->toBe('v1');
});

it('rejects a named path that could escape the project', function () {
    // Named separately from the config's own message, so it does not send the
    // reader to a file they never touched.
    expect(artisanOutput('modsx:scaffold Blog ../../etc'))->toContain('Invalid path');
});

it('rejects a name that could escape the project', function () {
    $this->artisan('modsx:scaffold', ['name' => '../etc'])->assertExitCode(1);
});

it('rejects a scaffold entry that could escape the project', function () {
    config()->set('modsx.scaffold', ['../../{Studly}']);

    $this->artisan('modsx:scaffold Blog')->assertExitCode(1);
});

it('says so when nothing is configured', function () {
    config()->set('modsx.scaffold', []);

    expect(artisanOutput('modsx:scaffold Blog'))->toContain('modsx.scaffold is empty');
});
