<?php
/**
 * Chunked ZIP packager.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Backup;

use ZipArchive;

if (! defined('ABSPATH')) {
    exit;
}

final class ZipPackager
{
    public function addFiles(BackupJob $job, int $limit): array
    {
        if (! class_exists(ZipArchive::class)) {
            return $job->update([
                'status' => 'failed',
                'errors' => array_merge($job->state()['errors'] ?? [], ['PHP ZipArchive extension is not available.']),
            ]);
        }

        $state = $job->state();
        $files = $state['files'] ?? [];
        $index = absint($state['file_index'] ?? 0);
        $root = trailingslashit(wp_normalize_path($state['root_path'] ?? ABSPATH));

        $zip = new ZipArchive();
        $mode = file_exists($job->packagePath()) ? ZipArchive::CREATE : (ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if (true !== $zip->open($job->packagePath(), $mode)) {
            return $job->update([
                'status' => 'failed',
                'errors' => array_merge($state['errors'] ?? [], ['Unable to open package ZIP for writing.']),
            ]);
        }

        $processed = 0;
        $total = count($files);

        while ($index < $total && $processed < $limit) {
            $relative = ltrim((string) $files[$index], '/');
            $absolute = wp_normalize_path($root . $relative);

            if ($this->isSafePath($absolute, $root) && is_readable($absolute) && is_file($absolute)) {
                $zip->addFile($absolute, 'site/' . $relative);
            }

            $index++;
            $processed++;
        }

        if (! $zip->close()) {
            return $job->update([
                'status' => 'failed',
                'errors' => array_merge($state['errors'] ?? [], ['Unable to finalize package ZIP after adding files.']),
            ]);
        }

        return $job->update([
            'file_index' => $index,
            'phase' => $index >= $total ? 'database' : 'files',
            'status' => $index >= $total ? 'files_done' : 'running',
        ]);
    }

    public function addGeneratedFiles(BackupJob $job): array
    {
        if (! class_exists(ZipArchive::class)) {
            return $job->update([
                'status' => 'failed',
                'errors' => array_merge($job->state()['errors'] ?? [], ['PHP ZipArchive extension is not available.']),
            ]);
        }

        $zip = new ZipArchive();

        if (true !== $zip->open($job->packagePath(), ZipArchive::CREATE)) {
            return $job->update([
                'status' => 'failed',
                'errors' => array_merge($job->state()['errors'] ?? [], ['Unable to append generated files to package ZIP.']),
            ]);
        }

        foreach ([
            $job->sqlPath() => 'database.sql',
            $job->installerPath() => 'installer.php',
            $job->compatibilityManifestPath() => 'compatibility-manifest.json',
        ] as $source => $local_name) {
            if (is_readable($source) && is_file($source)) {
                $zip->addFile($source, $local_name);
            } elseif ('database.sql' === $local_name || 'installer.php' === $local_name) {
                $zip->close();

                return $job->update([
                    'status' => 'failed',
                    'errors' => array_merge($job->state()['errors'] ?? [], [sprintf('Required package file is missing: %s', $local_name)]),
                ]);
            }
        }

        if (! $zip->close()) {
            return $job->update([
                'status' => 'failed',
                'errors' => array_merge($job->state()['errors'] ?? [], ['Unable to finalize package ZIP after adding generated files.']),
            ]);
        }

        return $job->update([
            'status' => 'completed',
            'phase' => 'done',
            'completed_at' => time(),
        ]);
    }

    private function isSafePath(string $path, string $root): bool
    {
        $real = wp_normalize_path(realpath($path) ?: '');

        return '' !== $real && 0 === strpos($real, $root);
    }
}
