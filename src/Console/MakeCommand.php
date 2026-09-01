<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\search;
use function Laravel\Prompts\text;

use Modsx\BackupRepository;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;
use Modsx\ModuleLocator;
use Modsx\ModuleMaker;
use Modsx\ModuleName;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\StringInput;

/**
 * Run one of Laravel's own generators, with the module written in for you.
 *
 * The convention this package is built on says a module's controller lives in
 * ModsxBlog/, its view in modsx-blog/ and its migration is named
 * modsx_blog_..., which means every call to a generator repeats the prefix and
 * has to pick the right form of the name. Typing it is not hard; typing it the
 * same way every time, at every generator, is - and getting it wrong makes two
 * modules that read as one.
 *
 * So this is a translation and nothing more: it works out the name, then hands
 * the call to the real generator. Whatever make:controller does, this does.
 */
class MakeCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modsx:make
                            {generator? : Generator to run, without the "make:" prefix}
                            {name? : Module/Name, e.g. Blog/PostController}
                            {extra?* : Options for the generator; -- before them is optional}
                            {--dry-run : Print the command instead of running it}';

    protected $description = "Run one of Laravel's generators with the module prefix filled in";

    /**
     * The generator's options are not ours to declare, and there is no
     * knowing them: every package brings its own. So this command stops
     * Symfony rejecting what it does not recognise and sorts the tokens
     * itself, which is what lets "--resource" be written where anyone would
     * write it rather than behind a "--" they have to remember.
     *
     * The cost is that a misspelling of our own option is no longer caught
     * here; it is forwarded, and the generator says it does not know it.
     * unknownOption() spots the near misses before that happens.
     */
    public function __construct()
    {
        parent::__construct();

        $this->ignoreValidationErrors();
    }

    /**
     * What was typed, sorted into the two positional arguments and everything
     * meant for the generator.
     *
     * Read from the raw tokens rather than the bound input, because binding
     * stops at the first option this command does not declare - which, now
     * that the generator's options need no "--", is most of them. Anything
     * after a "--" is the generator's whatever it looks like, so the form that
     * was required before still works.
     *
     * An input that carries no tokens - Artisan::call() with an array - never
     * had this problem, and falls back to what Symfony bound.
     *
     * @return array{?string, ?string, list<string>}
     */
    private function typed(): array
    {
        if (! $this->input instanceof ArgvInput) {
            /** @var list<string> $extra */
            $extra = (array) $this->argument('extra');

            return [
                $this->argument('generator') === null ? null : (string) $this->argument('generator'),
                $this->argument('name') === null ? null : (string) $this->argument('name'),
                $extra,
            ];
        }

        $positional = [];
        $forGenerator = [];
        $literal = false;

        foreach ($this->input->getRawTokens(true) as $token) {
            if ($literal) {
                $forGenerator[] = $token;

                continue;
            }

            if ($token === '--') {
                $literal = true;

                continue;
            }

            if (! str_starts_with($token, '-') || $token === '-') {
                $positional[] = $token;

                continue;
            }

            if (self::isOurs($token)) {
                continue;
            }

            $forGenerator[] = $token;
        }

        return [$positional[0] ?? null, $positional[1] ?? null, $forGenerator];
    }

    /**
     * A misspelling of our own option, before it is forwarded to a generator
     * that will only say it has never heard of it.
     *
     * @param  list<string>  $extra
     */
    private function unknownOption(array $extra): ?string
    {
        foreach ($extra as $token) {
            $name = strstr($token, '=', true) ?: $token;

            if (str_starts_with($name, '--') && levenshtein($name, '--dry-run') <= 2) {
                return $name;
            }
        }

        return null;
    }

    /**
     * True for the options this command answers itself, and for the ones every
     * artisan command carries - Symfony reads those off the raw tokens before
     * any command runs, so passing them on would only repeat them.
     */
    private static function isOurs(string $token): bool
    {
        $name = strstr($token, '=', true) ?: $token;

        return in_array($name, [
            '--dry-run',
            '--help', '-h', '--quiet', '-q', '--version', '-V',
            '--ansi', '--no-ansi', '--no-interaction', '-n',
            '--verbose', '-v', '-vv', '-vvv', '--env',
        ], true);
    }

    public function handle(ModuleMaker $maker, ModuleLocator $locator, BackupRepository $backups): int
    {
        $this->banner();

        [$typedGenerator, $typedName, $extra] = $this->typed();

        if (($misspelt = $this->unknownOption($extra)) !== null) {
            $this->components->error(sprintf('The "%s" option does not exist.', $misspelt));
            $this->components->warn('Did you mean "--dry-run"?');

            return self::FAILURE;
        }

        $generator = $this->resolveGenerator($typedGenerator);

        if ($generator === null || ! $this->generatorExists($generator, $typedName)) {
            return self::FAILURE;
        }

        $name = $this->resolveName($locator, $typedName);

        if ($name === null) {
            return self::FAILURE;
        }

        try {
            $target = $maker->resolve($generator, $name, $extra);
        } catch (ModsxException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        // A configured name points at a generator that has to exist too. Only
        // a config can get this wrong, so it is worth saying which entry did.
        if ($this->getApplication()?->has('make:'.$target['generator']) !== true) {
            $this->components->error(ModsxException::unknownGenerator($target['generator'])->getMessage());
            $this->components->warn(sprintf(
                'The modsx.generators entry for [%s] runs [%s], which this application does not have.',
                $generator,
                $target['generator'],
            ));

            return self::FAILURE;
        }

        $line = $this->commandLine($target['generator'], $target['name'], $target['options']);
        $unknown = ! $this->isKnownModule($target['module'], $locator, $backups);

        if ($unknown) {
            $this->warnUnknownModule($target['module'], $locator, $backups);
        }

        if ($this->input->hasParameterOption('--dry-run', true)) {
            $this->components->info('Would run:');
            $this->line('  <fg=green>php artisan '.$line.'</>');
            $this->newLine();

            return self::SUCCESS;
        }

        // Creating a file is not destructive, so an unknown module is a
        // question, not a refusal - and never one that stops a scripted run.
        if ($unknown && $this->input->isInteractive()
            && ! confirm(label: sprintf('Create [%s]?', $target['module']->studly), default: true)) {
            $this->components->info('Nothing was changed.');

            return self::SUCCESS;
        }

        $exitCode = $this->delegate($target['generator'], $line);

        if ($exitCode === self::SUCCESS) {
            $this->warnAboutLooseMigration($target['generator'], $target['options'], $target['module']);
        }

        return $exitCode;
    }

    /**
     * Hand the call to the real generator.
     *
     * Through the command's own run() rather than Artisan::call(), so we do not
     * re-enter the console application, and so a generator that throws throws
     * where we can see it. StringInput is what makes a forwarded option arrive
     * parsed exactly as the generator declared it, short clusters (-mfs)
     * included, which is why the command name is part of the line: Symfony
     * binds the first token to the "command" argument.
     */
    private function delegate(string $generator, string $line): int
    {
        $application = $this->getApplication();

        if ($application === null) {
            $this->components->error('No console application is available to run the generator.');

            return self::FAILURE;
        }

        $input = new StringInput($line);
        $input->setInteractive($this->input->isInteractive());

        return $application->find('make:'.$generator)->run($input, $this->output);
    }

    /**
     * @param  list<string>  $options
     */
    private function commandLine(string $generator, string $name, array $options): string
    {
        $tokens = ['make:'.$generator, $name, ...$options];

        return implode(' ', array_map($this->quote(...), $tokens));
    }

    private function quote(string $token): string
    {
        return preg_match('/\s/', $token) === 1
            ? '"'.str_replace('"', '\\"', $token).'"'
            : $token;
    }

    private function resolveGenerator(?string $given): ?string
    {
        if ($given !== null) {
            return $given;
        }

        $generators = $this->generators();

        if ($generators === []) {
            $this->components->error('No make: commands are registered in this application.');

            return null;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('Which generator? Pass one, e.g. "modsx:make controller Blog/PostController".');

            return null;
        }

        return (string) search(
            label: 'Which generator?',
            options: fn (string $value) => array_combine(
                $matches = array_values(array_filter(
                    $generators,
                    fn (string $name) => $value === '' || str_contains($name, strtolower($value)),
                )),
                $matches,
            ),
            scroll: 10,
        );
    }

    /**
     * Every make: command the application knows about, ours included: a
     * generator added by another package is wrapped just as well as Laravel's.
     *
     * @return list<string>
     */
    private function generators(): array
    {
        $names = [];

        foreach ($this->getApplication()?->all() ?? [] as $name => $command) {
            if (str_starts_with($name, 'make:') && ! $command->isHidden()) {
                $names[] = substr($name, strlen('make:'));
            }
        }

        // Names of your own from modsx.generators - "layout", "page" - are
        // generators as far as anyone using this command is concerned, so the
        // list has to offer them alongside Laravel's.
        foreach (array_keys((array) config('modsx.generators', [])) as $configured) {
            if ($configured !== '*') {
                $names[] = (string) $configured;
            }
        }

        $names = array_values(array_unique($names));

        sort($names);

        return $names;
    }

    /**
     * A name is usable when Laravel has a generator of that name, or when the
     * config gives it one - "layout" runs make:view and there is no make:layout.
     */
    private function generatorExists(string $generator, ?string $typedName): bool
    {
        if ($this->getApplication()?->has('make:'.$generator) === true) {
            return true;
        }

        if (array_key_exists($generator, (array) config('modsx.generators', []))) {
            return true;
        }

        $this->components->error(ModsxException::unknownGenerator($generator)->getMessage());

        // The whole deprecation path for 0.3.0's modsx:make, which took a
        // module name: one message, at the one moment it can be recognised.
        if ($typedName === null && ModuleName::tryMake($generator) !== null) {
            $this->components->warn(sprintf(
                'To create the directory skeleton for a module, that command is now '.
                '"php artisan modsx:scaffold %s".',
                $generator
            ));
        }

        return false;
    }

    private function resolveName(ModuleLocator $locator, ?string $given): ?string
    {
        if ($given !== null) {
            return $given;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('Which name? Pass one, e.g. "Blog/PostController".');

            return null;
        }

        $module = $this->pickModule($locator, 'Which module?');

        if ($module === null) {
            return null;
        }

        return $module.'/'.text(label: 'Name?', required: true);
    }

    private function isKnownModule(ModuleName $module, ModuleLocator $locator, BackupRepository $backups): bool
    {
        // A module that exists only in the backups is still a module: between
        // modsx:delete and modsx:restore there is nothing on disk to find, and
        // nagging about it would be wrong.
        return in_array($module->studly, $this->knownModules($locator, $backups), true);
    }

    private function warnUnknownModule(ModuleName $module, ModuleLocator $locator, BackupRepository $backups): void
    {
        $known = $this->knownModules($locator, $backups);

        $this->components->warn(sprintf('Module [%s] does not exist yet - this creates it.', $module->studly));

        // Without this, the warning above carries no information: the whole
        // point is to show that you meant Blog and typed Blogg.
        $closest = $this->closest($module->studly, $known);

        if ($closest !== null) {
            $this->components->warn(sprintf('Did you mean [%s]?', $closest));
        }

        if ($known !== []) {
            $this->components->twoColumnDetail('Existing modules', '<fg=gray>'.implode(', ', $known).'</>');
        }
    }

    /**
     * @return list<string>
     */
    private function knownModules(ModuleLocator $locator, BackupRepository $backups): array
    {
        $known = array_values(array_unique([...$locator->names(), ...$backups->modules()]));

        sort($known);

        return $known;
    }

    /**
     * @param  list<string>  $known
     */
    private function closest(string $name, array $known): ?string
    {
        $best = null;
        $distance = PHP_INT_MAX;

        foreach ($known as $candidate) {
            $candidateDistance = levenshtein(strtolower($name), strtolower($candidate));

            if ($candidateDistance < $distance) {
                $best = $candidate;
                $distance = $candidateDistance;
            }
        }

        // Far enough away and a suggestion is noise rather than help.
        return $distance <= max(2, intdiv(strlen($name), 3)) ? $best : null;
    }

    /**
     * make:model -m generates a migration named by Laravel, not by us, so it
     * lands outside the module: modsx will not back it up, restore it or list
     * it, and nothing says so. This is precisely the silent convention breakage
     * modsx:doctor exists to report, so we say it while the user is still here.
     *
     * @param  list<string>  $options
     */
    private function warnAboutLooseMigration(string $generator, array $options, ModuleName $module): void
    {
        if ($generator !== 'model' || ! $this->generatesMigration($options)) {
            return;
        }

        $this->newLine();
        $this->components->warn(sprintf(
            'The migration that came with the model is named by Laravel, so it does not '.
            'carry the [%s] prefix and does not belong to the module: it will not be backed '.
            'up with it. Rename it, or generate migrations with '.
            '"php artisan modsx:make migration %s/create_..._table". "modsx:doctor" lists any '.
            'that are already adrift.',
            $module->studly,
            $module->studly,
        ));
        $this->newLine();
    }

    /**
     * @param  list<string>  $options
     */
    private function generatesMigration(array $options): bool
    {
        foreach ($options as $option) {
            if ($option === '--migration' || $option === '--all') {
                return true;
            }

            // A short cluster carries them too: -mfs is -m -f -s.
            if (str_starts_with($option, '-') && ! str_starts_with($option, '--')
                && preg_match('/[ma]/', substr($option, 1)) === 1) {
                return true;
            }
        }

        return false;
    }
}
