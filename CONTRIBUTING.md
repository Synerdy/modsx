# Contributing

Thanks for considering a contribution.

## Getting started

```bash
git clone https://github.com/Synerdy/modsx.git
cd modsx
composer install
composer test
```

## Before opening a pull request

- `composer test` passes.
- `composer analyse` (PHPStan/Larastan, level 6) passes.
- `composer lint` has been run (Pint, Laravel preset).
- New behaviour comes with a test. Anything that writes to or deletes from the
  filesystem needs one - that is the part of this package that can lose
  someone's work.
- Commit messages and code comments are in English.

## Reporting bugs

Please include your PHP version, Laravel version, operating system and
filesystem, plus the contents of `config/modsx.php` if you have customised it.
Filesystem behaviour differs between platforms and it matters here more than in
most packages.

## Scope

This package manages module directories: finding, backing up, versioning,
restoring and removing them. It deliberately does not resolve dependencies
between modules, manage Composer requirements, or touch the database.
Proposals that expand it in those directions are likely to be declined - not
because they are bad ideas, but because they are a different package.
