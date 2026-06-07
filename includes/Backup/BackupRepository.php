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
    /**
     * Returns all discoverable backup packages.
     *
     * @return array
     */
    public function all(): array
    {
        $base_dir = $this->baseDir();
        $this->protectStorage();
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

    /**
     * Deletes one backup job directory.
     *
     * @param string $job_id Backup job ID.
     * @return bool
     */
    public function delete(string $job_id): bool
    {
        $job = new BackupJob($job_id);
        $directory = realpath($job->dir());
        $base = realpath($this->baseDir());

        if (false === $directory || false === $base || 0 !== strpos(wp_normalize_path($directory), trailingslashit(wp_normalize_path($base)))) {
            return false;
        }

        $manifest_path = trailingslashit($directory) . 'manifest.json';
        $state = is_readable($manifest_path) ? json_decode((string) file_get_contents($manifest_path), true) : null;

        if (! is_array($state) || sanitize_key((string) ($state['job_id'] ?? '')) !== $job->id()) {
            return false;
        }

        $this->deleteDirectory($directory);

        return ! is_dir($directory);
    }

    /**
     * Returns the base backup storage directory.
     *
     * @return string
     */
    public function baseDir(): string
    {
        $upload_dir = wp_upload_dir(null, false);

        return trailingslashit($upload_dir['basedir']) . 'atlas-backup-migration';
    }

    /**
     * Deletes a directory recursively with writable checks.
     *
     * @param string $directory Directory to delete.
     */
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
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    /**
     * Protects backup storage from direct web access.
     */
    private function protectStorage(): void
    {
        $directory = $this->baseDir();
        wp_mkdir_p($directory);

        $index = trailingslashit($directory) . 'index.php';
        $htaccess = trailingslashit($directory) . '.htaccess';

        if (! file_exists($index)) {
            file_put_contents($index, '', LOCK_EX);
        }

        if (! file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n", LOCK_EX);
        }

        $web_config = trailingslashit($directory) . 'web.config';

        if (! file_exists($web_config)) {
            file_put_contents(
                $web_config,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
                LOCK_EX
            );
        }
    }
}
