<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modsx\PathMover;

/**
 * An open handle inside a directory is what a file watcher leaves behind, and
 * on Windows it is what makes rename() of that directory fail. Reproduced here
 * rather than mocked, so the fallback is shown working against the real thing.
 */
function withHandleInside(string $file, callable $body): mixed
{
    $handle = fopen($file, 'r');

    try {
        return $body();
    } finally {
        fclose($handle);
    }
}

it('moves a directory by renaming it when nothing is in the way', function () {
    $from = $this->root.'/staging';
    $to = $this->root.'/final';

    File::ensureDirectoryExists($from);
    File::put($from.'/a.txt', 'content');

    expect(app(PathMover::class)->placeDirectory($from, $to))->toBeTrue()
        ->and(File::get($to.'/a.txt'))->toBe('content')
        ->and(File::isDirectory($from))->toBeFalse();
});

it('moves a directory a watcher is holding, by copying it', function () {
    $from = $this->root.'/staging';
    $to = $this->root.'/final';

    File::ensureDirectoryExists($from.'/nested');
    File::put($from.'/nested/a.txt', 'content');

    $moved = withHandleInside($from.'/nested/a.txt', fn () => app(PathMover::class)->placeDirectory($from, $to));

    expect($moved)->toBeTrue()
        ->and(File::get($to.'/nested/a.txt'))->toBe('content');
})->skip(PHP_OS_FAMILY !== 'Windows', 'Only Windows refuses to rename a directory something has open.');

it('moves a file something is holding, by copying it', function () {
    $from = $this->root.'/staging.txt';
    $to = $this->root.'/final.txt';

    File::put($from, 'content');

    $moved = withHandleInside($from, fn () => app(PathMover::class)->placeFile($from, $to));

    expect($moved)->toBeTrue()
        ->and(File::get($to))->toBe('content');
})->skip(PHP_OS_FAMILY !== 'Windows', 'Only Windows refuses to rename a file something has open.');

it('leaves nothing at the destination when it cannot get there', function () {
    // Half a version at the destination is worse than none: it would look like
    // something that could be restored.
    $to = $this->root.'/final';

    expect(app(PathMover::class)->placeDirectory($this->root.'/does-not-exist', $to))->toBeFalse()
        ->and(File::isDirectory($to))->toBeFalse();
});

it('reports failure rather than throwing when a file cannot be moved', function () {
    expect(app(PathMover::class)->placeFile($this->root.'/missing.txt', $this->root.'/final.txt'))->toBeFalse();
});

it('refuses to move a held directory rather than half-emptying it', function () {
    // move() has no copy fallback on purpose. Copying and then deleting the
    // original cannot be undone halfway: on Windows the delete stops at the
    // held file, and what is left is a directory emptied of everything except
    // the one thing nobody could touch.
    $from = $this->root.'/live';
    $to = $this->root.'/aside';

    File::ensureDirectoryExists($from);
    File::put($from.'/held.txt', 'content');
    File::put($from.'/other.txt', 'also here');

    $moved = withHandleInside($from.'/held.txt', fn () => app(PathMover::class)->moveDirectory($from, $to));

    expect($moved)->toBeFalse()
        ->and(File::get($from.'/held.txt'))->toBe('content')
        ->and(File::get($from.'/other.txt'))->toBe('also here')
        ->and(File::isDirectory($to))->toBeFalse();
})->skip(PHP_OS_FAMILY !== 'Windows', 'Only Windows refuses to rename a directory something has open.');

it('moves a directory out of the way when nothing is holding it', function () {
    $from = $this->root.'/live';
    $to = $this->root.'/aside';

    File::ensureDirectoryExists($from);
    File::put($from.'/a.txt', 'content');

    expect(app(PathMover::class)->moveDirectory($from, $to))->toBeTrue()
        ->and(File::isDirectory($from))->toBeFalse()
        ->and(File::get($to.'/a.txt'))->toBe('content');
});
