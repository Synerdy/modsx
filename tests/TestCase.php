<?php

declare(strict_types=1);

namespace Modsx\Tests;

use Illuminate\Support\Facades\File;
use Modsx\ModsxServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // Every test gets its own throwaway project root, so nothing here can
        // touch the real filesystem beyond the system temp directory.
        $this->root = rtrim(sys_get_temp_dir(), '/').'/modsx-'.bin2hex(random_bytes(6));

        File::ensureDirectoryExists($this->root.'/app');
        File::ensureDirectoryExists($this->root.'/resources/views');
        File::ensureDirectoryExists($this->root.'/storage/app');

        $this->app->setBasePath($this->root);

        config()->set('modsx.backup_path', $this->root.'/ModulesX');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [ModsxServiceProvider::class];
    }

    /**
     * Create a module directory containing one file.
     */
    protected function makeModuleDirectory(string $relative, string $file = 'placeholder.txt', string $contents = 'x'): string
    {
        $path = $this->root.'/'.trim($relative, '/');

        File::ensureDirectoryExists($path);
        File::put($path.'/'.$file, $contents);

        return $path;
    }

    /**
     * Create an empty backup version directory, as if one had been made.
     */
    protected function makeBackupVersion(string $module, string $version): string
    {
        $path = $this->root.'/ModulesX/'.$module.'/'.$version;

        File::ensureDirectoryExists($path);

        return $path;
    }
}
