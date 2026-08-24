<?php

declare(strict_types=1);

use Modsx\ModsxServiceProvider;

it('never returns a version string with a leading v', function () {
    // Composer reports the git tag verbatim (e.g. "v0.2.3"); version() must
    // strip it, since callers like the command banner add their own "v" and
    // the backup manifest wants a plain semver string.
    expect(ModsxServiceProvider::version())->not->toMatch('/^v\d/i');
});
