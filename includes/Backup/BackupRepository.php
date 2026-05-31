<?php
/**
 * Backup package repository.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Backup;

if (! defined('ABSPATH')) {
    exit;
}

final class BackupRepository
{
    public function all(): array
    {
        $base_dir = $this->baseDir();
        $items = [];

        if (! is_dir($base_dir)) {
            return [];
        }

        foreach (glob(trailingslashit($base_dir) . '*', GLOB_ONLYDIR) ?: [] as $directory) {
            $manifest_path = trailingslashit($directory) . 'manifest.json';

            if (! is_readable($manifest_path)) {
                continue;
            }

            $state = json_decode((string) file_get_contents($manifest_path), true);

            if (! is_array($state) || empty($state['job_id'])) {
                continue;
            }

            $job = new BackupJob((string) $state['job_id']);
            $package_path = $job->packagePath();

            if (! is_file($package_path)) {
                continue;
            }

            $items[] = [
                'job_id' => $job->id(),
                'type' => sanitize_key($state['backup_type'] ?? 'full'),
                'label' => sanitize_text_field($state['label'] ?? $job->id()),
                'status' => sanitize_key($state['status'] ?? ''),
                'phase' => sanitize_key($state['phase'] ?? ''),
                'created_at' => absint($state['created_at'] ?? filemtime($package_path)),
                'completed_at' => absint($state['completed_at'] ?? 0),
                'size' => (int) (filesize($package_path) ?: 0),
                'package_name' => basename($package_path),
                'downloads' => $job->downloadUrls(),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            return ($right['created_at'] ?? 0) <=> ($left['created_at'] ?? 0);
        });

        return $items;
    }

    public function delete(string $job_id): bool
    {
        $job = new BackupJob($job_id);
        $directory = realpath($job->dir());
        $base = realpath($this->baseDir());

        if (false === $directory || false === $base || 0 !== strpos(wp_normalize_path($directory), trailingslashit(wp_normalize_path($base)))) {
            return false;
        }

        $this->deleteDirectory($directory);

        return ! is_dir($directory);
    }

    public function baseDir(): string
    {
        $upload_dir = wp_upload_dir(null, false);

        return trailingslashit($upload_dir['basedir']) . 'atlas-backup-migration';
    }

    private function deleteDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = trailingslashit($directory) . $entry;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }

            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
