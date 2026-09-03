<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\BackupManager;

beforeEach(function () {
    $this->makeModuleDirectory('app/Http/Controllers/ModsxBlog', 'PostController.php', 'v1');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', 'v1');
});

function status(string $arguments = ''): array
{
    return json_decode(artisanOutput(trim('modsx:status '.$arguments).' --json'), true);
}

it('calls a module clean right after a backup', function () {
    app(BackupManager::class)->backup('Blog');

    expect(status()['Blog'])->toMatchArray([
        'state' => 'clean',
        'current' => '0001',
        'latest' => '0001',
        'changes' => 0,
        'behind' => false,
        'stale' => false,
    ]);
});

it('counts what has changed since the version it came from', function () {
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    File::put($this->root.'/resources/views/modsx-blog/show.blade.php', 'new');
    File::delete($this->root.'/app/Http/Controllers/ModsxBlog/PostController.php');

    expect(status()['Blog'])->toMatchArray([
        'state' => 'modified',
        'current' => '0001',
        'changes' => 3,
    ]);
});

it('calls a module with no backups untracked', function () {
    $this->makeModuleDirectory('resources/views/modsx-shop', 'index.blade.php');

    expect(status()['Shop'])->toMatchArray([
        'state' => 'untracked',
        'current' => null,
        'latest' => null,
        'changes' => null,
    ]);
});

it('calls a module that is only in the backups missing', function () {
    app(BackupManager::class)->backup('Blog');
    app(BackupManager::class)->delete('Blog');

    expect(status()['Blog'])->toMatchArray([
        'state' => 'missing',
        'current' => null,
        'latest' => '0001',
        'changes' => null,
    ]);
});

it('says a module is behind when a newer version exists than the one it came from', function () {
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    app(BackupManager::class)->backup('Blog');

    app(BackupManager::class)->restore('Blog', '0001');

    expect(status()['Blog'])->toMatchArray([
        'state' => 'clean',
        'current' => '0001',
        'latest' => '0002',
        'changes' => 0,
        'behind' => true,
    ]);
});

it('warns in the listing that backing up now would build on the older version', function () {
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    app(BackupManager::class)->backup('Blog');

    app(BackupManager::class)->restore('Blog', '0001');

    expect(artisanOutput('modsx:status'))
        ->toContain('working from 0001, but 0002 exists');
});

it('falls back to the newest version when the one it came from was pruned', function () {
    app(BackupManager::class)->backup('Blog');

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    app(BackupManager::class)->backup('Blog');

    app(BackupManager::class)->restore('Blog', '0001');
    app(BackupManager::class)->prune('Blog', 1);

    expect(status()['Blog'])->toMatchArray([
        'state' => 'modified',
        'current' => '0001',
        'latest' => '0002',
        'stale' => true,
        'behind' => false,
    ]);
});

it('reports a module made by hand as untracked, having never been backed up', function () {
    // The pointer plays no part in finding modules: this one was made with
    // mkdir and appears here exactly as it always has.
    expect(status()['Blog'])->toMatchArray([
        'state' => 'untracked',
        'current' => null,
    ]);
});

it('lists a single module when one is named', function () {
    $this->makeModuleDirectory('resources/views/modsx-shop', 'index.blade.php');

    expect(array_keys(status('Blog')))->toBe(['Blog']);
});

it('fails for a module that is neither in the application nor in the backups', function () {
    $this->artisan('modsx:status Ghost --json')->assertExitCode(1);
});

it('emits valid json with no banner in it', function () {
    app(BackupManager::class)->backup('Blog');

    $raw = artisanOutput('modsx:status --json');

    expect(json_decode($raw, true))->toBeArray()
        ->and($raw)->not->toContain('█');
});

it('shows every module in one table', function () {
    $this->makeModuleDirectory('resources/views/modsx-shop', 'index.blade.php');
    app(BackupManager::class)->backup('Blog');

    $output = artisanOutput('modsx:status');

    expect($output)->toContain('Blog')
        ->and($output)->toContain('Shop')
        ->and($output)->toContain('clean')
        ->and($output)->toContain('untracked');
});
