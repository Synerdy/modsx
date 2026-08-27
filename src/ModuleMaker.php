<?php

declare(strict_types=1);

namespace Modsx;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Console\Migrations\TableGuesser;
use Illuminate\Support\Str;
use Modsx\Exceptions\ModsxException;

/**
 * Works out what to hand Laravel's own generators for a module.
 *
 * "Blog/PostController" is not a name any generator understands - the module
 * has to be written out in the form that generator expects, which is a
 * different form for each one:
 *
 *   make:controller  ModsxBlog/PostController
 *   make:view        modsx-blog/index
 *   make:migration   modsx_blog_create_posts_table
 *
 * Remembering which is which, at every generator, is exactly the mistake
 * modsx:doctor exists to find after the fact. Here the three forms come from
 * one ModuleName and a table in the config, so they cannot disagree.
 *
 * This class runs nothing. It returns a description of the call, which keeps
 * the whole mapping testable without a filesystem or a console application.
 */
class ModuleMaker
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ModuleLocator $locator,
    ) {}

    /**
     * @param  list<string>  $extra  options for the generator, verbatim
     * @return array{module: ModuleName, generator: string, name: string, options: list<string>}
     *
     * @throws ModsxException
     */
    public function resolve(string $generator, string $rawName, array $extra = []): array
    {
        $generator = trim($generator);

        [$module, $tail] = $this->split($rawName);

        if ($generator === 'migration') {
            $tail = Str::snake(trim($tail));
        }

        return [
            'module' => $module,
            'generator' => $generator,
            'name' => $this->modulePrefix($generator, $module).$tail,
            'options' => $generator === 'migration'
                ? [...$extra, ...$this->tableOption($tail, $extra)]
                : $extra,
        ];
    }

    /**
     * @return array{ModuleName, string}
     *
     * @throws ModsxException
     */
    private function split(string $rawName): array
    {
        // Both separators are accepted. PowerShell escapes with a backtick, so
        // a backslash reaches us intact there and is the natural thing to type;
        // in a POSIX shell it is eaten before the process starts, which is what
        // missingModuleSegment() is there to explain.
        $normalised = trim(str_replace('\\', '/', trim($rawName)), '/');

        if (! str_contains($normalised, '/')) {
            throw ModsxException::missingModuleSegment($rawName);
        }

        [$module, $tail] = explode('/', $normalised, 2);

        if (trim($tail) === '') {
            throw ModsxException::missingModuleSegment($rawName);
        }

        return [ModuleName::make($module), $tail];
    }

    /**
     * The module, written the way this generator expects to read it.
     *
     * @throws ModsxException
     */
    private function modulePrefix(string $generator, ModuleName $module): string
    {
        /** @var array<string, string> $patterns */
        $patterns = (array) $this->config->get('modsx.generators', []);

        $pattern = (string) ($patterns[$generator] ?? $patterns['*'] ?? '{Studly}/');

        return str_replace(
            ['{Studly}', '{kebab}', '{snake}'],
            [
                $this->locator->prefixStudly().$module->studly,
                $this->locator->prefix().'-'.$module->kebab,
                $this->locator->prefix().'_'.$module->snake,
            ],
            $pattern
        );
    }

    /**
     * Laravel guesses the table from the migration name, and our name defeats
     * the guess: TableGuesser matches /^create_(\w+)_table$/, which
     * "modsx_blog_create_posts_table" cannot satisfy with the module in front.
     * Without this, every create-migration would silently come out as an empty
     * stub instead of a Schema::create. (Change migrations happen to survive,
     * because their pattern is anchored at the end: /.+_(to|from|in)_(\w+)_table$/.)
     *
     * So we run the guess against the tail - the part actually written by the
     * user - and pass the answer explicitly.
     *
     * @param  list<string>  $extra
     * @return list<string>
     */
    private function tableOption(string $tail, array $extra): array
    {
        // Guessing is a convenience, not a correction: an explicit --create or
        // --table always wins.
        foreach ($extra as $option) {
            if (str_starts_with($option, '--create') || str_starts_with($option, '--table')) {
                return [];
            }
        }

        // illuminate/database is not a dependency of this package. If it is
        // absent there is no make:migration to wrap either, so this is simply
        // nothing to do rather than a problem.
        if (! class_exists(TableGuesser::class)) {
            return [];
        }

        /** @var array{string, bool}|null $guess */
        $guess = TableGuesser::guess($tail);

        if ($guess === null) {
            return [];
        }

        [$table, $create] = $guess;

        return [($create ? '--create=' : '--table=').$table];
    }
}
