<?php

declare(strict_types=1);

namespace Modsx;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\File;
use Modsx\Exceptions\ModsxException;

/**
 * Creates the directory skeleton for a new module.
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
     * @return array{created: list<string>, skipped: list<string>}
     *
     * @throws ModsxException
     */
    public function scaffold(ModuleName|string $name): array
    {
        $name = ModuleName::make($name);

        $created = [];
        $skipped = [];

        foreach ($this->templates() as $template) {
            $relative = $this->fill($template, $name);
            $path = base_path($relative);

            if (File::isDirectory($path)) {
                $skipped[] = $relative;

                continue;
            }

            File::ensureDirectoryExists($path);
            $created[] = $relative;
        }

        return ['created' => $created, 'skipped' => $skipped];
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
