<?php
/**
 * Imports product payloads received over REST sync.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Sync;

use AtlasBackupMigration\Import\IdRemapper;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

final class ProductImporter
{
    public function import(array $payload, array $id_map = [])
    {
        $post_data = is_array($payload['post'] ?? null) ? $payload['post'] : [];

        if (empty($post_data['post_title']) || empty($post_data['post_type'])) {
            return new WP_Error('abm_bad_product_payload', __('Invalid product payload.', 'atlas-backup-migration'), ['status' => 400]);
        }

        $existing_id = $this->findExisting(absint($payload['source_id'] ?? 0));
        $post_data = [
            'ID' => $existing_id,
            'post_type' => in_array($post_data['post_type'], ['product', 'product_variation'], true) ? $post_data['post_type'] : 'product',
            'post_status' => sanitize_key($post_data['post_status'] ?? 'draft'),
            'post_title' => sanitize_text_field((string) $post_data['post_title']),
            'post_content' => wp_kses_post((string) ($post_data['post_content'] ?? '')),
            'post_excerpt' => wp_kses_post((string) ($post_data['post_excerpt'] ?? '')),
            'post_name' => sanitize_title($post_data['post_name'] ?? ''),
            'menu_order' => absint($post_data['menu_order'] ?? 0),
            'meta_input' => [
                '_abm_source_product_id' => absint($payload['source_id'] ?? 0),
                '_abm_source_checksum' => sanitize_text_field($payload['checksum'] ?? ''),
            ],
        ];

        $product_id = $existing_id ? wp_update_post($post_data, true) : wp_insert_post($post_data, true);

        if (is_wp_error($product_id)) {
            return $product_id;
        }

        $this->importMeta((int) $product_id, is_array($payload['meta'] ?? null) ? $payload['meta'] : [], $id_map);
        $this->importTerms((int) $product_id, is_array($payload['terms'] ?? null) ? $payload['terms'] : []);
        $this->attachMedia((int) $product_id, is_array($payload['media'] ?? null) ? $payload['media'] : [], $id_map);

        return [
            'product_id' => (int) $product_id,
            'updated' => (bool) $existing_id,
        ];
    }

    private function findExisting(int $source_id): int
    {
        if (! $source_id) {
            return 0;
        }

        $posts = get_posts([
            'post_type' => ['product', 'product_variation'],
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => 1,
            'meta_key' => '_abm_source_product_id',
            'meta_value' => $source_id,
        ]);

        return $posts ? absint($posts[0]) : 0;
    }

    private function importMeta(int $product_id, array $meta, array $id_map): void
    {
        $blocked = ['_edit_lock', '_edit_last', '_thumbnail_id', '_product_image_gallery'];
        $remapper = new IdRemapper();

        foreach ($meta as $key => $values) {
            $key = sanitize_key($key);

            if ('' === $key || in_array($key, $blocked, true)) {
                continue;
            }

            delete_post_meta($product_id, $key);

            foreach ((array) $values as $value) {
                add_post_meta($product_id, $key, $this->sanitizeMetaValue($this->remapMetaValue($key, $value, $id_map, $remapper)));
            }
        }
    }

    private function remapMetaValue(string $key, $value, array $id_map, IdRemapper $remapper)
    {
        if ([] === $id_map) {
            return $value;
        }

        $media_keys = [
            '_downloadable_files',
            '_wc_variation_gallery_images',
            '_product_image_gallery',
            '_thumbnail_id',
        ];

        if (in_array($key, $media_keys, true) || false !== strpos($key, 'image') || false !== strpos($key, 'attachment') || false !== strpos($key, 'media') || false !== strpos($key, 'gallery')) {
            return $remapper->remapValue($value, $id_map);
        }

        return $value;
    }

    private function sanitizeMetaValue($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'sanitizeMetaValue'], $value);
        }

        if (is_object($value)) {
            return $value;
        }

        return is_scalar($value) || null === $value ? wp_kses_post((string) $value) : '';
    }

    private function importTerms(int $product_id, array $terms): void
    {
        foreach ($terms as $taxonomy => $items) {
            $taxonomy = sanitize_key($taxonomy);

            if (! taxonomy_exists($taxonomy)) {
                continue;
            }

            $term_ids = [];

            foreach ((array) $items as $item) {
                $name = sanitize_text_field($item['name'] ?? '');

                if ('' === $name) {
                    continue;
                }

                $term = term_exists($name, $taxonomy);

                if (! $term) {
                    $term = wp_insert_term($name, $taxonomy, [
                        'slug' => sanitize_title($item['slug'] ?? $name),
                        'description' => sanitize_textarea_field($item['description'] ?? ''),
                    ]);
                }

                if (! is_wp_error($term)) {
                    $term_ids[] = absint(is_array($term) ? $term['term_id'] : $term);
                }
            }

            wp_set_object_terms($product_id, $term_ids, $taxonomy);
        }
    }

    private function attachMedia(int $product_id, array $media, array $id_map): void
    {
        $attachment_ids = [];

        foreach ($media as $item) {
            if (! is_array($item)) {
                continue;
            }

            $source_id = absint($item['source_id'] ?? 0);

            if (! $source_id) {
                continue;
            }

            if (isset($id_map[$source_id])) {
                $attachment_ids[] = absint($id_map[$source_id]);
                continue;
            }

            $matches = get_posts([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'fields' => 'ids',
                'numberposts' => 1,
                'meta_key' => '_abm_source_attachment_id',
                'meta_value' => $source_id,
            ]);

            if ($matches) {
                $attachment_ids[] = absint($matches[0]);
            }
        }

        $attachment_ids = array_values(array_unique(array_filter($attachment_ids)));

        if ([] === $attachment_ids) {
            return;
        }

        update_post_meta($product_id, '_thumbnail_id', $attachment_ids[0]);

        if (count($attachment_ids) > 1) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', array_slice($attachment_ids, 1)));
        }
    }
}
