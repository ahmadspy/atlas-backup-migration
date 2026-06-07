<?php
/**
 * Builds focused migration packages.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Export;

use AtlasBackupMigration\Backup\BackupJob;
use AtlasBackupMigration\Sync\ProductPayloadBuilder;
use ZipArchive;

if (! defined('ABSPATH')) {
    exit;
}

final class GranularExportService
{
    public function export(array $request): array
    {
        if (! class_exists(ZipArchive::class)) {
            return [
                'success' => false,
                'message' => __('PHP ZipArchive extension is not available.', 'atlas-backup-migration'),
            ];
        }

        $type = sanitize_key($request['export_type'] ?? 'woocommerce');
        $label = sanitize_text_field($request['label'] ?? '');
        $job = BackupJob::create();
        $package_name = 'atlas-granular-' . $type . '-' . $job->id() . '.zip';
        $job->update([
            'backup_type' => 'granular',
            'granular_type' => $type,
            'label' => $label ?: $this->typeLabel($type),
            'package_name' => $package_name,
            'status' => 'running',
            'phase' => 'granular_export',
        ]);

        $payload = $this->payload($type, $request);

        if ([] === $payload) {
            $state = $job->update([
                'status' => 'failed',
                'errors' => [__('Nothing exportable was found for the selected module.', 'atlas-backup-migration')],
            ]);

            return [
                'success' => false,
                'message' => $state['errors'][0],
            ];
        }

        $zip = new ZipArchive();

        if (true !== $zip->open($job->packagePath(), ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            $job->update([
                'status' => 'failed',
                'errors' => [__('Unable to create granular package.', 'atlas-backup-migration')],
            ]);

            return [
                'success' => false,
                'message' => __('Unable to create granular package.', 'atlas-backup-migration'),
            ];
        }

        $zip->addFromString('data.json', (string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->addMediaFiles($zip, $payload);
        $this->addThemeFiles($zip, $payload);

        if (! $zip->close()) {
            $job->update([
                'status' => 'failed',
                'errors' => [__('Unable to finalize granular package.', 'atlas-backup-migration')],
            ]);

            return [
                'success' => false,
                'message' => __('Unable to finalize granular package.', 'atlas-backup-migration'),
            ];
        }

        $state = $job->update([
            'status' => 'completed',
            'phase' => 'done',
            'completed_at' => time(),
            'manifest' => [
                'type' => $type,
                'item_count' => absint($payload['item_count'] ?? 0),
            ],
        ]);

        return [
            'success' => true,
            'job_id' => $job->id(),
            'state' => $state,
            'downloads' => $job->downloadUrls(),
        ];
    }

    private function payload(string $type, array $request): array
    {
        switch ($type) {
            case 'theme':
                return $this->themePayload(sanitize_key($request['theme_slug'] ?? get_stylesheet()));
            case 'elementor':
                return $this->elementorPayload();
            case 'woocommerce':
            default:
                return $this->woocommercePayload();
        }
    }

    private function woocommercePayload(): array
    {
        $product_ids = get_posts([
            'post_type' => ['product', 'product_variation'],
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => -1,
        ]);

        $products = [];
        $builder = new ProductPayloadBuilder();

        foreach (array_map('absint', $product_ids) as $product_id) {
            $payload = $builder->build($product_id);

            if ([] !== $payload) {
                $products[] = $payload;
            }
        }

        return [
            'schema' => 'atlas-granular/v1',
            'type' => 'woocommerce',
            'site_url' => site_url(),
            'created_at' => gmdate('c'),
            'item_count' => count($products),
            'products' => $products,
        ];
    }

    private function elementorPayload(): array
    {
        $post_ids = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => -1,
            'meta_key' => '_elementor_data',
        ]);

        $items = [];

        foreach (array_map('absint', $post_ids) as $post_id) {
            $post = get_post($post_id);

            if (! $post) {
                continue;
            }

            $meta = [
                '_elementor_data' => get_post_meta($post_id, '_elementor_data', true),
                '_elementor_page_settings' => get_post_meta($post_id, '_elementor_page_settings', true),
                '_elementor_edit_mode' => get_post_meta($post_id, '_elementor_edit_mode', true),
                '_wp_page_template' => get_post_meta($post_id, '_wp_page_template', true),
            ];

            $items[] = [
                'source_id' => $post_id,
                'post' => [
                    'post_type' => $post->post_type,
                    'post_status' => $post->post_status,
                    'post_title' => $post->post_title,
                    'post_content' => $post->post_content,
                    'post_excerpt' => $post->post_excerpt,
                    'post_name' => $post->post_name,
                    'menu_order' => (int) $post->menu_order,
                ],
                'meta' => $meta,
                'media' => $this->mediaPayloads($this->elementorMediaIds($meta)),
            ];
        }

        return [
            'schema' => 'atlas-granular/v1',
            'type' => 'elementor',
            'site_url' => site_url(),
            'created_at' => gmdate('c'),
            'item_count' => count($items),
            'pages' => $items,
        ];
    }

    private function themePayload(string $theme_slug): array
    {
        $theme = wp_get_theme($theme_slug);

        if (! $theme->exists()) {
            return [];
        }

        return [
            'schema' => 'atlas-granular/v1',
            'type' => 'theme',
            'site_url' => site_url(),
            'created_at' => gmdate('c'),
            'item_count' => 1,
            'theme' => [
                'slug' => $theme_slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'stylesheet' => get_option('stylesheet'),
                'template' => get_option('template'),
                'mods' => get_theme_mods(),
                'options' => $this->themeOptions($theme_slug),
                'path' => wp_normalize_path($theme->get_stylesheet_directory()),
            ],
        ];
    }

    private function themeOptions(string $theme_slug): array
    {
        global $wpdb;

        $like = $wpdb->esc_like($theme_slug) . '%';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ('stylesheet', 'template') OR option_name LIKE %s OR option_name LIKE %s",
                'theme_mods_' . $like,
                $like
            ),
            ARRAY_A
        );

        $options = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $options[(string) $row['option_name']] = maybe_unserialize($row['option_value']);
        }

        return $options;
    }

    private function addMediaFiles(ZipArchive $zip, array $payload): void
    {
        $added = [];

        foreach ($this->payloadMediaItems($payload) as $media) {
            if (! is_array($media)) {
                continue;
            }

            $source_id = absint($media['source_id'] ?? 0);
            $path = $source_id ? get_attached_file($source_id) : '';

            if (! is_string($path) || ! is_readable($path) || isset($added[$source_id])) {
                continue;
            }

            $zip->addFile($path, 'media/' . $source_id . '-' . sanitize_file_name(basename($path)));
            $added[$source_id] = true;
        }
    }

    private function payloadMediaItems(array $payload): array
    {
        $items = [];

        if (is_array($payload['media'] ?? null)) {
            $items = array_merge($items, $payload['media']);
        }

        foreach (['products', 'pages'] as $collection_key) {
            foreach ((array) ($payload[$collection_key] ?? []) as $entry) {
                if (is_array($entry) && is_array($entry['media'] ?? null)) {
                    $items = array_merge($items, $entry['media']);
                }
            }
        }

        return $items;
    }

    private function mediaPayloads(array $attachment_ids): array
    {
        $items = [];

        foreach (array_values(array_unique(array_filter(array_map('absint', $attachment_ids)))) as $attachment_id) {
            if ('attachment' !== get_post_type($attachment_id)) {
                continue;
            }

            $path = get_attached_file($attachment_id);

            if (! is_string($path) || '' === $path) {
                continue;
            }

            $items[] = $this->attachmentPayload($attachment_id);
        }

        return $items;
    }

    private function attachmentPayload(int $attachment_id): array
    {
        $path = get_attached_file($attachment_id);
        $path = is_string($path) ? $path : '';

        return [
            'source_id' => $attachment_id,
            'title' => get_the_title($attachment_id),
            'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'filename' => basename($path),
            'mime_type' => get_post_mime_type($attachment_id),
            'size' => is_file($path) ? (int) (filesize($path) ?: 0) : 0,
            'sha256' => is_file($path) ? (hash_file('sha256', $path) ?: '') : '',
            'url' => wp_get_attachment_url($attachment_id),
        ];
    }

    private function elementorMediaIds(array $meta): array
    {
        $ids = [];

        foreach ($meta as $value) {
            $ids = array_merge($ids, $this->collectMediaIds($this->decodeStructuredValue($value)));
        }

        return array_values(array_unique(array_filter(array_map('absint', $ids))));
    }

    private function collectMediaIds($value): array
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            $ids = [];

            if (isset($value['id']) && is_numeric($value['id'])) {
                $ids[] = absint($value['id']);
            }

            foreach ($value as $item) {
                $ids = array_merge($ids, $this->collectMediaIds($this->decodeStructuredValue($item)));
            }

            return $ids;
        }

        return [];
    }

    private function decodeStructuredValue($value)
    {
        if (! is_string($value) || '' === $value) {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $unslashed = wp_unslash($value);

        if ($unslashed !== $value) {
            $decoded = json_decode($unslashed, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $unserialized = @unserialize($value, ['allowed_classes' => false]);

        if (false !== $unserialized || 'b:0;' === $value) {
            return $unserialized;
        }

        return $value;
    }

    private function addThemeFiles(ZipArchive $zip, array $payload): void
    {
        $theme_path = $payload['theme']['path'] ?? '';

        if ('theme' !== ($payload['type'] ?? '') || ! is_string($theme_path) || ! is_dir($theme_path)) {
            return;
        }

        $base = trailingslashit(wp_normalize_path(realpath($theme_path) ?: $theme_path));
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->isLink() || ! $file->isFile() || ! $file->isReadable()) {
                continue;
            }

            $path = wp_normalize_path($file->getPathname());

            if (0 !== strpos(wp_normalize_path(realpath($path) ?: ''), $base)) {
                continue;
            }

            $relative = ltrim(str_replace($base, '', $path), '/');
            $zip->addFile($path, 'files/theme/' . $relative);
        }
    }

    private function typeLabel(string $type): string
    {
        $labels = [
            'woocommerce' => __('WooCommerce products package', 'atlas-backup-migration'),
            'elementor' => __('Elementor pages package', 'atlas-backup-migration'),
            'theme' => __('Theme package', 'atlas-backup-migration'),
        ];

        return $labels[$type] ?? __('Granular package', 'atlas-backup-migration');
    }
}
