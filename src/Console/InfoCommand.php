<?php

declare(strict_types=1);

namespace Modsx\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Modsx\BackupRepository;
use Modsx\Console\Concerns\InteractsWithModules;
use Modsx\Exceptions\ModsxException;
use Modsx\ModuleLocator;

/**
 * Show what a module consists of and what it costs on disk.
 *
 * Works for modules that exist only in backups as well as modules present in
 * the application, because "how big is this thing and when did I last back it
 * up" is a question people ask about modules they have already removed.
 */
class InfoCommand extends Command
{
    use InteractsWithModules;

    protected $signature = 'modules:info
                            {name? : Module name; omit to pick from a list}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Show directories, size and backup history for a module';

    public function handle(ModuleLocator $locator, BackupRepository $backups): int
    {
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->banner();
        }

        $name = $this->argument('name') ?? $this->pickKnownModule($locator, $backups, 'Which module?');

        if ($name === null) {
            return self::FAILURE;
        }

        try {
            $paths = $locator->paths((string) $name);
            $versions = $backups->versions((string) $name);
        } catch (ModsxException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($paths === [] && $versions === []) {
            $this->components->error(sprintf(
                'Module [%s] is neither in the application nor in the backups.',
                $name
            ));

            return self::FAILURE;
        }

        $info = [
            'module' => (string) $name,
            'application' => $this->applicationInfo($paths),
            'backups' => $this->backupInfo($backups, (string) $name, $versions),
        ];

        if ($json) {
            $this->line((string) json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($info);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function applicationInfo(array $paths): array
    {
        $bytes = 0;
        $files = 0;
        $directories = [];

        foreach ($paths as $relative) {
            [$pathBytes, $pathFiles] = $this->measure(base_path($relative));

            $bytes += $pathBytes;
            $files += $pathFiles;

            $directories[] = [
                'path' => $relative,
                'files' => $pathFiles,
                'size_bytes' => $pathBytes,
                'size' => $this->formatBytes($pathBytes),
            ];
        }

        return [
            'present' => $paths !== [],
            'directories' => $directories,
            'files' => $files,
            'size_bytes' => $bytes,
            'size' => $this->formatBytes($bytes),
        ];
    }

    /**
     * @param  list<string>  $versions
     * @return array<string, mixed>
     */
    private function backupInfo(BackupRepository $backups, string $name, array $versions): array
    {
        $rows = [];
        $total = 0;

        foreach ($versions as $version) {
            $path = $backups->versionPath($name, $version);
            [$bytes, $files] = $this->measure($path);

            $total += $bytes;

            $manifest = $backups->manifest($name, $version);

            $rows[] = [
                'version' => $version,
                'created_at' => is_string($manifest['created_at'] ?? null)
                    ? $manifest['created_at']
                    : (File::isDirectory($path) ? date(DATE_ATOM, (int) filemtime($path)) : null),
                'files' => $files,
                'size_bytes' => $bytes,
                'size' => $this->formatBytes($bytes),
                'laravel_version' => $manifest['laravel_version'] ?? null,
                'php_version' => $manifest['php_version'] ?? null,
            ];
        }

        return [
            'count' => count($rows),
            'total_size_bytes' => $total,
            'total_size' => $this->formatBytes($total),
            'versions' => $rows,
        ];
    }

    /**
     * @return array{0: int, 1: int} bytes, file count
     */
    private function measure(string $path): array
    {
        if (! File::isDirectory($path)) {
            return [0, 0];
        }

        $bytes = 0;
        $files = 0;

        foreach (File::allFiles($path) as $file) {
            $bytes += (int) $file->getSize();
            $files++;
        }

        return [$bytes, $files];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0
            ? sprintf('%d B', (int) $value)
            : sprintf('%.1f %s', $value, $units[$unit]);
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function render(array $info): void
    {
        $application = $info['application'];
        $backups = $info['backups'];

        $this->newLine();
        $this->components->info(sprintf('Module [%s]', $info['module']));

        if ($application['present']) {
            $this->components->twoColumnDetail('Status', '<fg=green>present in the application</>');
            $this->components->twoColumnDetail('Directories', (string) count($application['directories']));
            $this->components->twoColumnDetail('Files', (string) $application['files']);
            $this->components->twoColumnDetail('Size', $application['size']);

            $this->newLine();

            foreach ($application['directories'] as $directory) {
                $this->components->twoColumnDetail(
                    $directory['path'],
                    sprintf('<fg=gray>%d file(s), %s</>', $directory['files'], $directory['size'])
                );
            }
        } else {
            $this->components->twoColumnDetail('Status', '<fg=yellow>not in the application</>');
            $this->components->twoColumnDetail('Restore with', 'php artisan modules:restore '.$info['module']);
        }

        $this->newLine();

        if ($backups['count'] === 0) {
            $this->components->warn('This module has no backups.');
            $this->newLine();

            return;
        }

        $this->components->info(sprintf(
            '%d backup version(s), %s total',
            $backups['count'],
            $backups['total_size'],
        ));

        $this->table(
            ['Version', 'Created', 'Files', 'Size', 'Laravel'],
            array_map(static fn (array $row): array => [
                $row['version'],
                $row['created_at'] ?? '-',
                (string) $row['files'],
                $row['size'],
                $row['laravel_version'] ?? '-',
            ], $backups['versions']),
        );
    }
}
