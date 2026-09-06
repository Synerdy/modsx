<?php

declare(strict_types=1);

namespace Modsx;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\File;
use Modsx\Exceptions\ModsxException;

/**
 * Creates the skeleton for a new module: directories, and a file wherever an
 * entry names one.
 *
 * This is the one place in the package that puts something into the
 * application rather than reading or copying it, and it stays deliberately
 * small: directories only, never files, never code. Generating stubs would
 * make Modsx a code generator, which is precisely what it is not.
 *
 * What it does buy is correctness. The naming convention's one trap is writing
 * the two directory forms from two different names - "modsx-userprofile" next
 * to "ModsxUserProfile" - which produces two modules that look like one.
 * Deriving both from a single ModuleName makes that mistake impossible to
 * make by hand.
 */
class ModuleScaffolder
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ModuleLocator $locator,
    ) {}

    /**
     * @param  list<string>  $paths  directories to create; the configured list when empty
     * @return array{created: list<string>, skipped: list<string>, files: list<string>}
     *
     * @throws ModsxException
     */
    public function scaffold(ModuleName|string $name, array $paths = []): array
    {
        $name = ModuleName::make($name);

        $created = [];
        $skipped = [];
        $files = [];

        $templates = $paths === [] ? $this->templates() : $this->templatesFor($paths);

        foreach ($templates as $template) {
            $relative = $this->fill($template, $name);
            $path = base_path($relative);
            $isFile = self::namesAFile($relative);

            if ($isFile) {
                $files[] = $relative;
            }

            // Never File::exists() for a directory: a file sitting where a
            // directory should go is a different problem, and reporting it as
            // "already existed" would hide it.
            if ($isFile ? File::exists($path) : File::isDirectory($path)) {
                $skipped[] = $relative;

                continue;
            }

            if ($isFile) {
                File::ensureDirectoryExists(dirname($path));
                File::put($path, '');
            } else {
                File::ensureDirectoryExists($path);
            }

            $created[] = $relative;
        }

        return ['created' => $created, 'skipped' => $skipped, 'files' => $files];
    }

    /**
     * Does this path name a file rather than a directory?
     *
     * Read off the last segment having a dot in it, which is what tells
     * "docs/modsx-blog.md" from "docs/modsx-blog". The rule is the filename's
     * own shape rather than a list of blessed extensions, because the entries
     * are yours to write and any list would be somebody else's guess at what
     * you keep in a module.
     *
     * A directory deliberately named with a dot - "modsx-blog.v2" - would be
     * read as a file. Rare enough to accept, and the answer is to write the
     * entry without one.
     */
    private static function namesAFile(string $relative): bool
    {
        return str_contains(basename($relative), '.');
    }

    /**
     * Turn directories typed on the command line into templates.
     *
     * You write the path as it looks in the project - "resources/css",
     * "app/Services" - and the form of the module's own directory is read off
     * where that path leads. Directories under app/, database/ and tests/ are
     * PSR-4 namespace segments, where a hyphen is not a legal PHP identifier,
     * so those take the StudlyCase form; everywhere else the name is only ever
     * a path, so it takes kebab-case. That is the convention's own rule rather
     * than a new one.
     *
     * Writing a placeholder yourself settles it instead, for the layouts this
     * cannot know about - a PSR-4 root of your own, say.
     *
     * @param  list<string>  $paths
     * @return list<string>
     *
     * @throws ModsxException
     */
    private function templatesFor(array $paths): array
    {
        $templates = [];

        foreach ($paths as $path) {
            $template = trim(str_replace('\\', '/', trim($path)), '/ ');

            if ($template === '' || str_contains($template, '..')) {
                throw ModsxException::invalidPath($path);
            }

            $templates[] = str_contains($template, '{')
                ? $template
                : $template.'/'.self::formFor($template);
        }

        return $templates;
    }

    /**
     * The placeholder a directory takes, from the root it sits under.
     */
    private static function formFor(string $path): string
    {
        $root = strtok($path, '/');

        return in_array($root, ['app', 'database', 'tests'], true) ? '{Studly}' : '{kebab}';
    }

    private function fill(string $template, ModuleName $name): string
    {
        return str_replace(
            ['{Studly}', '{kebab}'],
            [
                $this->locator->prefixStudly().$name->studly,
                $this->locator->prefix().'-'.$name->kebab,
            ],
            $template
        );
    }

    /**
     * @return list<string>
     *
     * @throws ModsxException
     */
    private function templates(): array
    {
        $templates = [];

        foreach ((array) $this->config->get('modsx.scaffold', []) as $value) {
            $template = trim(str_replace('\\', '/', (string) $value), '/ ');

            if ($template === '') {
                continue;
            }

            // The module name cannot escape the project root - ModuleName sees
            // to that - but a hand-edited config entry could.
            if (str_contains($template, '..')) {
                throw ModsxException::invalidScaffoldPath($template);
            }

            $templates[] = $template;
        }

        return $templates;
    }
}
