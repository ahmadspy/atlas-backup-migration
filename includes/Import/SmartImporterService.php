<?php
/**
 * Imports focused migration packages.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Import;

use AtlasBackupMigration\Sync\ProductImporter;
use WP_Error;
use ZipArchive;

if (! defined('ABSPATH')) {
    exit;
}

final class SmartImporterService
{
    public function import(array $file)
    {
        if (! class_exists(ZipArchive::class)) {
            return new WP_Error('abm_zip_missing', __('PHP ZipArchive extension is not available.', 'atlas-backup-migration'), ['status' => 500]);
        }

        if (empty($file['tmp_name']) || ! is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('abm_bad_upload', __('No import package was uploaded.', 'atlas-backup-migration'), ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $upload = wp_handle_upload($file, ['test_form' => false, 'mimes' => ['zip' => 'application/zip']]);

        if (! empty($upload['error'])) {
            return new WP_Error('abm_upload_failed', (string) $upload['error'], ['status' => 400]);
        }

        $package = (string) $upload['file'];
        $work_dir = trailingslashit(wp_upload_dir(null, false)['basedir']) . 'atlas-backup-migration/import-' . wp_generate_uuid4();
        wp_mkdir_p($work_dir);

        $zip = new ZipArchive();

        if (true !== $zip->open($package)) {
            $this->cleanup($work_dir);
            return new WP_Error('abm_import_open_failed', __('Unable to open import package.', 'atlas-backup-migration'), ['status' => 400]);
        }

        $this->safeExtract($zip, $work_dir);
        $zip->close();

        $data_path = trailingslashit($work_dir) . 'data.json';
        $data = is_readable($data_path) ? json_decode((string) file_get_contents($data_path), true) : null;

        if (! is_array($data) || 'atlas-granular/v1' !== ($data['schema'] ?? '')) {
            $this->cleanup($work_dir);
            return new WP_Error('abm_bad_import_package', __('Import package data.json is invalid.', 'atlas-backup-migration'), ['status' => 400]);
        }

        $result = $this->dispatch($data, $work_dir);
        $this->cleanup($work_dir);

        return $result;
    }

    private function dispatch(array $data, string $work_dir)
    {
        switch (sanitize_key($data['type'] ?? '')) {
            case 'woocommerce':
                return $this->importWooCommerce($data, $work_dir);
            case 'elementor':
                return $this->importElementor($data, $work_dir);
            case 'theme':
                return $this->importTheme($data, $work_dir);
            default:
                return new WP_Error('abm_unknown_import_type', __('This granular package type is not supported.', 'atlas-backup-migration'), ['status' => 400]);
        }
    }

    private function importWooCommerce(array $data, string $work_dir): array
    {
        $media_map = $this->importPackagedMedia($work_dir, $this->collectMediaMetadata($data));
        $importer = new ProductImporter();
        $imported = 0;

        foreach (($data['products'] ?? []) as $product) {
            if (! is_array($product)) {
                continue;
            }

            $result = $importer->import($product, $media_map);

            if (! is_wp_error($result)) {
                $imported++;
            }
        }

        return [
            'type' => 'woocommerce',
            'imported' => $imported,
            'media_remapped' => count($media_map),
            'message' => sprintf(__('Imported %d WooCommerce products with %d remapped media items.', 'atlas-backup-migration'), $imported, count($media_map)),
        ];
    }

    private function importElementor(array $data, string $work_dir): array
    {
        $media_map = $this->importPackagedMedia($work_dir, $this->collectMediaMetadata($data));
        $remapper = new IdRemapper();
        $imported = 0;

        foreach (($data['pages'] ?? []) as $item) {
            $post_data = is_array($item['post'] ?? null) ? $item['post'] : [];

            if (empty($post_data['post_title'])) {
                continue;
            }

            $post_id = wp_insert_post([
                'post_type' => sanitize_key($post_data['post_type'] ?? 'page'),
                'post_status' => sanitize_key($post_data['post_status'] ?? 'draft'),
                'post_title' => sanitize_text_field($post_data['post_title']),
                'post_content' => wp_kses_post($post_data['post_content'] ?? ''),
                'post_excerpt' => wp_kses_post($post_data['post_excerpt'] ?? ''),
                'post_name' => sanitize_title($post_data['post_name'] ?? ''),
                'menu_order' => absint($post_data['menu_order'] ?? 0),
                'meta_input' => [
                    '_abm_source_elementor_id' => absint($item['source_id'] ?? 0),
                ],
            ], true);

            if (is_wp_error($post_id)) {
                continue;
            }

            foreach ((array) ($item['meta'] ?? []) as $key => $value) {
                $meta_key = sanitize_key($key);

                if ('' === $meta_key) {
                    continue;
                }

                if ('_elementor_data' === $meta_key) {
                    $value = $remapper->remapElementorJson($value, $media_map);
                    $value = is_string($value) ? wp_slash($value) : $value;
                } else {
                    $value = $remapper->remapValue($value, $media_map);
                }

                update_post_meta((int) $post_id, $meta_key, $value);
            }

            $imported++;
        }

        return [
            'type' => 'elementor',
            'imported' => $imported,
            'media_remapped' => count($media_map),
            'message' => sprintf(__('Imported %d Elementor pages/templates with %d remapped media items.', 'atlas-backup-migration'), $imported, count($media_map)),
        ];
    }

    private function importTheme(array $data, string $work_dir): array
    {
        $theme = is_array($data['theme'] ?? null) ? $data['theme'] : [];
        $slug = sanitize_key($theme['slug'] ?? '');

        if ('' === $slug) {
            return [
                'type' => 'theme',
                'imported' => 0,
                'message' => __('Theme slug is missing from package.', 'atlas-backup-migration'),
            ];
        }

        $source = trailingslashit($work_dir) . 'files/theme';
        $target = trailingslashit(get_theme_root()) . $slug;

        if (is_dir($source)) {
            $this->copyDirectory($source, $target);
        }

        foreach ((array) ($theme['options'] ?? []) as $name => $value) {
            update_option(sanitize_key($name), $value);
        }

        if (! empty($theme['mods']) && is_array($theme['mods'])) {
            update_option('theme_mods_' . $slug, $theme['mods']);
        }

        return [
            'type' => 'theme',
            'imported' => 1,
            'message' => sprintf(__('Imported theme package for %s.', 'atlas-backup-migration'), $slug),
        ];
    }

    private function importPackagedMedia(string $work_dir, array $media_metadata = []): array
    {
        $map = [];

        foreach (glob(trailingslashit($work_dir) . 'media/*') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $basename = basename($path);
            $source_id = absint(strtok($basename, '-'));
            $contents = file_get_contents($path);

            if (! $source_id || false === $contents) {
                continue;
            }

            $upload = wp_upload_bits(preg_replace('/^\d+-/', '', $basename), null, $contents);

            if (! empty($upload['error'])) {
                continue;
            }

            $metadata = is_array($media_metadata[$source_id] ?? null) ? $media_metadata[$source_id] : [];
            $title = sanitize_text_field($metadata['title'] ?? pathinfo($upload['file'], PATHINFO_FILENAME));
            $mime_type = sanitize_mime_type((string) ($metadata['mime_type'] ?? '')) ?: (wp_check_filetype($upload['file'])['type'] ?: 'application/octet-stream');

            $attachment_id = wp_insert_attachment([
                'post_title' => $title,
                'post_mime_type' => $mime_type,
                'post_status' => 'inherit',
            ], $upload['file']);

            if (is_wp_error($attachment_id)) {
                continue;
            }

            require_once ABSPATH . 'wp-admin/includes/image.php';
            wp_update_attachment_metadata((int) $attachment_id, wp_generate_attachment_metadata((int) $attachment_id, $upload['file']));
            update_post_meta((int) $attachment_id, '_abm_source_attachment_id', $source_id);

            if (! empty($metadata['alt'])) {
                update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field($metadata['alt']));
            }

            if (! empty($metadata['sha256'])) {
                update_post_meta((int) $attachment_id, '_abm_source_attachment_sha256', sanitize_text_field($metadata['sha256']));
            }

            $map[$source_id] = (int) $attachment_id;
        }

        return $map;
    }

    private function collectMediaMetadata(array $data): array
    {
        $media = [];
        $groups = [];

        if (! empty($data['media']) && is_array($data['media'])) {
            $groups[] = $data['media'];
        }

        foreach (['products', 'pages'] as $collection_key) {
            foreach ((array) ($data[$collection_key] ?? []) as $item) {
                if (is_array($item) && is_array($item['media'] ?? null)) {
                    $groups[] = $item['media'];
                }
            }
        }

        foreach ($groups as $group) {
            foreach ((array) $group as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $source_id = absint($item['source_id'] ?? 0);

                if ($source_id) {
                    $media[$source_id] = $item;
                }
            }
        }

        return $media;
    }

    private function safeExtract(ZipArchive $zip, string $target): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->getNameIndex($index);

            if (! is_string($entry) || false !== strpos($entry, '../') || false !== strpos($entry, '..\\')) {
                continue;
            }

            $destination = trailingslashit($target) . ltrim(str_replace('\\', '/', $entry), '/');

            if ('/' === substr($entry, -1)) {
                wp_mkdir_p($destination);
                continue;
            }

            wp_mkdir_p(dirname($destination));
            $stream = $zip->getStream($entry);

            if (is_resource($stream)) {
                file_put_contents($destination, stream_get_contents($stream), LOCK_EX);
                fclose($stream);
            }
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        wp_mkdir_p($target);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $destination = trailingslashit($target) . ltrim(str_replace(wp_normalize_path($source), '', wp_normalize_path($item->getPathname())), '/');

            if ($item->isDir()) {
                wp_mkdir_p($destination);
                continue;
            }

            copy($item->getPathname(), $destination);
        }
    }

    private function cleanup(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = trailingslashit($directory) . $entry;

            if (is_dir($path)) {
                $this->cleanup($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
