<?php
/**
 * Safe filesystem scanner.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Backup;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

if (! defined('ABSPATH')) {
    exit;
}

final class FileScanner
{
    public function scan(string $root_path, string $exclude_path, array $required_files = [], array $required_prefixes = []): array
    {
        $root_path = trailingslashit(wp_normalize_path(realpath($root_path) ?: $root_path));
        $exclude_path = trailingslashit(wp_normalize_path(realpath($exclude_path) ?: $exclude_path));
        $files = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root_path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile() || ! $file->isReadable()) {
                    continue;
                }

                $path = wp_normalize_path($file->getPathname());

                if (0 === strpos($path, $exclude_path)) {
                    continue;
                }

                $relative = ltrim(str_replace($root_path, '', $path), '/');

                if ($this->shouldSkip($relative)) {
                    continue;
                }

                $files[] = $relative;
            }
        } catch (UnexpectedValueException $exception) {
            unset($exception);
        }

        foreach ($required_files as $required_file) {
            $relative = ltrim((string) $required_file, '/');
            $absolute = wp_normalize_path($root_path . $relative);

            if (is_file($absolute) && is_readable($absolute)) {
                $files[] = $relative;
            }
        }

        foreach ($required_prefixes as $prefix) {
            $prefix = trim((string) $prefix, '/');

            if ('' === $prefix) {
                continue;
            }

            foreach ($files as $file) {
                if (0 === strpos($file, $prefix)) {
                    continue 2;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private function shouldSkip(string $relative_path): bool
    {
        $blocked = [
            '.git/',
            'node_modules/',
            'vendor/bin/',
            'wp-content/cache/',
            'wp-content/upgrade/',
            'wp-content/uploads/atlas-backup-migration/',
        ];

        foreach ($blocked as $needle) {
            if (false !== strpos($relative_path, $needle)) {
                return true;
            }
        }

        return false;
    }
}
