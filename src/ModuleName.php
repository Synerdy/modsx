<?php

declare(strict_types=1);

namespace Modsx;

use Illuminate\Support\Str;
use Modsx\Exceptions\ModsxException;
use Stringable;

/**
 * The canonical name of a module, plus the forms derived from it.
 *
 * A module has exactly one name, written in StudlyCase. Every other form is
 * derived from it, using the same conversion Laravel itself uses to turn
 * App\View\Components\UserProfile into <x-user-profile>:
 *
 *   UserProfile  ->  ModsxUserProfile   (PHP namespace directories)
 *   UserProfile  ->  modsx-user-profile (view, css, js, lang, public directories
 *                                        and the module's own files)
 *   UserProfile  ->  modsx_user_profile (migration filenames, which are snake_case)
 *
 * Deriving all of them from one name is the whole point: it is what makes
 * "modsx-userprofile next to ModsxUserProfile" a mistake rather than a
 * supported layout.
 *
 * Constructing a name through this class is also the single point where user
 * input is validated, which is what keeps names out of filesystem paths where
 * they do not belong.
 */
final class ModuleName implements Stringable
{
    private function __construct(
        public readonly string $studly,
        public readonly string $kebab,
        public readonly string $snake,
    ) {}

    /**
     * @throws ModsxException when the value cannot be a module name
     */
    public static function make(self|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $trimmed = trim($value);

        // Reject anything that could travel outside the directory we build:
        // separators, dots, and every other character that is not part of a name.
        if ($trimmed === '' || preg_match('/[^A-Za-z0-9 _-]/', $trimmed) === 1) {
            throw ModsxException::invalidModuleName($value);
        }

        $studly = Str::studly($trimmed);

        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $studly) !== 1) {
            throw ModsxException::invalidModuleName($value);
        }

        return new self($studly, Str::kebab($studly), Str::snake($studly));
    }

    /**
     * Same as make(), but returns null instead of throwing.
     *
     * Used when reading names off the filesystem, where an unexpected directory
     * should be skipped rather than break the whole listing.
     */
    public static function tryMake(self|string $value): ?self
    {
        try {
            return self::make($value);
        } catch (ModsxException) {
            return null;
        }
    }

    public function equals(self $other): bool
    {
        return $this->studly === $other->studly;
    }

    public function __toString(): string
    {
        return $this->studly;
    }
}
