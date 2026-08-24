<?php

declare(strict_types=1);

use Modsx\BackupRepository;

it('returns no versions when the backup directory does not exist', function () {
    $repository = app(BackupRepository::class);

    expect($repository->versions('Blog'))->toBe([])
        ->and($repository->latest('Blog'))->toBeNull()
        ->and($repository->modules())->toBe([]);
});

it('starts numbering at one', function () {
    expect(app(BackupRepository::class)->nextVersion('Blog'))->toBe('0001');
});

it('orders versions numerically regardless of filesystem order', function () {
    foreach (['0003', '0001', '0010', '0002'] as $version) {
        $this->makeBackupVersion('Blog', $version);
    }

    expect(app(BackupRepository::class)->versions('Blog'))
        ->toBe(['0001', '0002', '0003', '0010']);
});

it('derives the next version from the highest number, not the last listed', function () {
    // Regression: a gap left by a manually deleted version must never make the
    // counter reuse a number that is already on disk.
    foreach (['0001', '0003', '0004', '0005'] as $version) {
        $this->makeBackupVersion('Blog', $version);
    }

    expect(app(BackupRepository::class)->nextVersion('Blog'))->toBe('0006');
});

it('ignores directories that are not version numbers', function () {
    $this->makeBackupVersion('Blog', '0001');
    $this->makeBackupVersion('Blog', 'notes');

    expect(app(BackupRepository::class)->versions('Blog'))->toBe(['0001']);
});

it('only lists modules that actually have versions', function () {
    $this->makeBackupVersion('Blog', '0001');
    mkdir($this->root.'/ModulesX/Empty', 0777, true);

    expect(app(BackupRepository::class)->modules())->toBe(['Blog']);
});
