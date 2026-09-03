<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\SnapshotManager;
use Modsx\SnapshotRepository;

beforeEach(function () {
    $this->makeModuleDirectory('resources/views/modsx-user', 'card.blade.php', 'user v1');
    $this->makeModuleDirectory('resources/views/modsx-blog', 'index.blade.php', "blog v1 @include('modsx-user.card')");
});

it('takes a snapshot of the whole project', function () {
    $output = json_decode(artisanOutput('modsx:snapshot --json'), true);

    expect($output['snapshot'])->toBe('0001')
        ->and($output['modules'])->toBe(['Blog' => '0001', 'User' => '0001'])
        ->and($output['root'])->toBeNull();
});

it('takes a snapshot around one module and what it needs', function () {
    $this->makeModuleDirectory('resources/views/modsx-shop', 'index.blade.php', 'unrelated');

    $output = json_decode(artisanOutput('modsx:snapshot Blog --json'), true);

    expect(array_keys($output['modules']))->toBe(['Blog', 'User'])
        ->and($output['root'])->toBe('Blog');
});

it('records a comment with the snapshot', function () {
    artisanOutput('modsx:snapshot --comment="before the rewrite" --json');

    expect(app(SnapshotRepository::class)->read('0001')['comment'])->toBe('before the rewrite');
});

it('fails to snapshot a project with no modules', function () {
    File::deleteDirectory($this->root.'/resources/views');

    $this->artisan('modsx:snapshot --json')->assertExitCode(1);
});

it('lists the snapshots taken', function () {
    app(SnapshotManager::class)->take(comment: 'first');
    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    app(SnapshotManager::class)->take(comment: 'second');

    $output = json_decode(artisanOutput('modsx:snapshotlist --json'), true);

    expect($output['snapshots'])->toHaveCount(2)
        ->and($output['snapshots'][0]['comment'])->toBe('first')
        ->and($output['dangling'])->toBe([]);
});

it('fails to list snapshots when none have been taken', function () {
    $this->artisan('modsx:snapshotlist --json')->assertExitCode(1);
});

it('shows the scope of each snapshot in the listing', function () {
    app(SnapshotManager::class)->take();
    app(SnapshotManager::class)->take('Blog');

    $output = artisanOutput('modsx:snapshotlist');

    expect($output)->toContain('whole project')
        ->and($output)->toContain('Blog');
});

it('rolls the project back to a snapshot', function () {
    app(SnapshotManager::class)->take();

    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'blog v2');

    $this->artisan('modsx:rollback 0001 --force --json')->assertExitCode(0);

    expect(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toContain('blog v1');
});

it('rolls back to the newest snapshot when none is named', function () {
    app(SnapshotManager::class)->take();
    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'blog v2');
    app(SnapshotManager::class)->take();
    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'blog v3');

    $this->artisan('modsx:rollback --force --json')->assertExitCode(0);

    expect(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toBe('blog v2');
});

it('names the snapshot it left behind', function () {
    app(SnapshotManager::class)->take();
    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'blog v2');

    $output = json_decode(artisanOutput('modsx:rollback 0001 --force --json'), true);

    expect($output['safety'])->toBe('0002');
});

it('changes nothing without --force, even asked for json', function () {
    // Machine-readable output is not permission. A script has to say --force.
    app(SnapshotManager::class)->take();
    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'blog v2');

    $this->artisan('modsx:rollback 0001 --json --no-interaction')->assertExitCode(1);

    expect(File::get($this->root.'/resources/views/modsx-blog/index.blade.php'))->toBe('blog v2');
});

it('fails to roll back to a snapshot that does not exist', function () {
    app(SnapshotManager::class)->take();

    $this->artisan('modsx:rollback 9999 --force --json')->assertExitCode(1);
});

it('fails to roll back when no snapshot has been taken', function () {
    $this->artisan('modsx:rollback --force --json')->assertExitCode(1);
});

it('lets old snapshots go', function () {
    foreach (range(1, 3) as $n) {
        File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v'.$n);
        app(SnapshotManager::class)->take();
    }

    $output = json_decode(artisanOutput('modsx:snapshotprune --keep=1 --force --json'), true);

    expect($output['removed'])->toBe(['0001', '0002'])
        ->and(app(SnapshotRepository::class)->ids())->toBe(['0003']);
});

it('removes no snapshots on a dry run', function () {
    app(SnapshotManager::class)->take();
    File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v2');
    app(SnapshotManager::class)->take();

    $output = json_decode(artisanOutput('modsx:snapshotprune --keep=1 --dry-run --json'), true);

    expect($output['plan'])->toBe(['0001'])
        ->and(app(SnapshotRepository::class)->ids())->toBe(['0001', '0002']);
});

it('shows the dependency graph', function () {
    $output = json_decode(artisanOutput('modsx:deps --json'), true);

    expect($output['modules']['Blog'])->toBe([['module' => 'User', 'via' => 'scan']])
        ->and($output['modules']['User'])->toBe([])
        ->and($output['cycles'])->toBe([]);
});

it('shows what a snapshot of one module would hold', function () {
    $output = json_decode(artisanOutput('modsx:deps Blog --json'), true);

    expect($output['closure'])->toBe(['Blog', 'User']);
});

it('says where each edge came from', function () {
    $this->makeModuleDirectory('resources/views/modsx-search', 'index.blade.php', 'x');
    config()->set('modsx.dependencies', ['Blog' => ['Search']]);

    $output = artisanOutput('modsx:deps Blog');

    expect($output)->toContain('found in the code')
        ->and($output)->toContain('declared in config');
});

it('removes no snapshots without --force, even asked for json', function () {
    foreach (range(1, 3) as $n) {
        File::put($this->root.'/resources/views/modsx-blog/index.blade.php', 'v'.$n);
        app(SnapshotManager::class)->take();
    }

    artisanOutput('modsx:snapshotprune --keep=1 --json --no-interaction');

    expect(app(SnapshotRepository::class)->ids())->toBe(['0001', '0002', '0003']);
});
