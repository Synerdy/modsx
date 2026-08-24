<?php

declare(strict_types=1);

use Modsx\Exceptions\ModsxException;
use Modsx\ModuleName;

it('derives both directory forms from one canonical name', function (string $input, string $studly, string $kebab) {
    $name = ModuleName::make($input);

    expect($name->studly)->toBe($studly)
        ->and($name->kebab)->toBe($kebab);
})->with([
    ['Blog', 'Blog', 'blog'],
    ['blog', 'Blog', 'blog'],
    ['UserProfile', 'UserProfile', 'user-profile'],
    ['user-profile', 'UserProfile', 'user-profile'],
    ['user_profile', 'UserProfile', 'user-profile'],
]);

it('treats names differing only in word boundaries as different modules', function () {
    // This is the trap the doctor command exists to catch: both are valid.
    expect(ModuleName::make('userprofile')->studly)->toBe('Userprofile')
        ->and(ModuleName::make('user-profile')->studly)->toBe('UserProfile');
});

it('rejects names that could escape the backup directory', function (string $input) {
    ModuleName::make($input);
})->with([
    '../etc',
    '../../secrets',
    'foo/bar',
    'foo\\bar',
    'foo.bar',
    '',
    '   ',
    '*',
])->throws(ModsxException::class);

it('returns null instead of throwing when asked to try', function () {
    expect(ModuleName::tryMake('../etc'))->toBeNull()
        ->and(ModuleName::tryMake('Blog'))->not->toBeNull();
});
