<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\ModuleLocator;

it('creates both directory forms from one name', function () {
    $this->artisan('modsx:make Blog')->assertExitCode(0);

    expect(File::isDirectory($this->root.'/app/Http/Controllers/ModsxBlog'))->toBeTrue()
        ->and(File::isDirectory($this->root.'/app/Models/ModsxBlog'))->toBeTrue()
        ->and(File::isDirectory($this->root.'/resources/views/modsx-blog'))->toBeTrue();
});

it('derives both forms from the same name, whatever case is typed', function () {
    // The trap this command exists to close: hand-writing "modsx-userprofile"
    // next to "ModsxUserProfile" makes two modules that read as one.
    $this->artisan('modsx:make user-profile')->assertExitCode(0);

    expect(File::isDirectory($this->root.'/app/Models/ModsxUserProfile'))->toBeTrue()
        ->and(File::isDirectory($this->root.'/resources/views/modsx-user-profile'))->toBeTrue();
});

it('produces a module the locator then finds', function () {
    $this->artisan('modsx:make Blog')->assertExitCode(0);

    // Empty directories are still directories, so discovery sees them.
    expect(app(ModuleLocator::class)->names())->toBe(['Blog']);
});

it('skips directories that already exist instead of failing', function () {
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'keep me');

    $output = json_decode(artisanOutput('modsx:make Blog --json'), true);

    expect($output['skipped'])->toContain('resources/views/modsx-blog')
        ->and($output['created'])->toContain('app/Models/ModsxBlog')
        ->and(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toBe('keep me');
});

it('follows the configured scaffold rather than a fixed layout', function () {
    config()->set('modsx.scaffold', ['app/Livewire/{Studly}', 'resources/css/{kebab}']);

    $output = json_decode(artisanOutput('modsx:make Blog --json'), true);

    expect($output['created'])->toBe(['app/Livewire/ModsxBlog', 'resources/css/modsx-blog']);
});

it('rejects a name that could escape the project', function () {
    $this->artisan('modsx:make', ['name' => '../etc'])->assertExitCode(1);
});

it('rejects a scaffold entry that could escape the project', function () {
    config()->set('modsx.scaffold', ['../../{Studly}']);

    $this->artisan('modsx:make Blog')->assertExitCode(1);
});

it('says so when nothing is configured', function () {
    config()->set('modsx.scaffold', []);

    expect(artisanOutput('modsx:make Blog'))->toContain('modsx.scaffold is empty');
});
