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
    public function import(array $payload, array $id_map = [], array $product_id_map = [], array $term_id_map = [], array $term_taxonomy_id_map = [], bool $finalize_relationships = true)
    {
        $post_data = is_array($payload['post'] ?? null) ? $payload['post'] : [];

        if (empty($post_data['post_title']) || empty($post_data['post_type'])) {
            return new WP_Error('abm_bad_product_payload', __('Invalid product payload.', 'atlas-backup-migration'), ['status' => 400]);
        }

        $existing_id = $this->findExisting(absint($payload['source_id'] ?? 0));
        $source_parent_id = absint($post_data['post_parent'] ?? 0);
        $post_data = [
            'ID' => $existing_id,
            'post_type' => in_array($post_data['post_type'], ['product', 'product_variation'], true) ? $post_data['post_type'] : 'product',
            'post_status' => sanitize_key($post_data['post_status'] ?? 'draft'),
            'post_title' => sanitize_text_field((string) $post_data['post_title']),
            'post_content' => wp_kses_post((string) ($post_data['post_content'] ?? '')),
            'post_excerpt' => wp_kses_post((string) ($post_data['post_excerpt'] ?? '')),
            'post_name' => sanitize_title($post_data['post_name'] ?? ''),
            'post_parent' => $this->mappedProductId($source_parent_id, $product_id_map),
            'menu_order' => absint($post_data['menu_order'] ?? 0),
            'meta_input' => [
                '_abm_source_product_id' => absint($payload['source_id'] ?? 0),
                '_abm_source_parent_id' => $source_parent_id,
                '_abm_source_checksum' => sanitize_text_field($payload['checksum'] ?? ''),
            ],
        ];

        $product_id = $existing_id ? wp_update_post($post_data, true) : wp_insert_post($post_data, true);

        if (is_wp_error($product_id)) {
            return $product_id;
        }

        $source_product_id = absint($payload['source_id'] ?? 0);

        if ($source_product_id) {
            $product_id_map[$source_product_id] = (int) $product_id;
        }

        $this->importMeta((int) $product_id, is_array($payload['meta'] ?? null) ? $payload['meta'] : [], $id_map);
        $term_relationships_remapped = $this->importTerms(
            (int) $product_id,
            is_array($payload['terms'] ?? null) ? $payload['terms'] : [],
            $term_id_map,
            $term_taxonomy_id_map
        );
        $this->attachMedia((int) $product_id, is_array($payload['media'] ?? null) ? $payload['media'] : [], $id_map);
        $variation_parents_remapped = $finalize_relationships ? $this->remapProductParents($product_id_map) : 0;
        $term_parents_remapped = $finalize_relationships ? $this->remapTermParents($term_id_map, $term_taxonomy_id_map) : 0;

        return [
            'product_id' => (int) $product_id,
            'source_id' => $source_product_id,
            'source_parent_id' => $source_parent_id,
            'product_id_map' => $product_id_map,
            'term_id_map' => $term_id_map,
            'term_taxonomy_id_map' => $term_taxonomy_id_map,
            'term_relationships_remapped' => $term_relationships_remapped,
            'variation_parents_remapped' => $variation_parents_remapped,
            'term_parents_remapped' => $term_parents_remapped,
            'updated' => (bool) $existing_id,
        ];
    }

    public function remapProductParents(array $product_id_map): int
    {
        if ([] === $product_id_map) {
            return 0;
        }

        $updated = 0;
        $variations = get_posts([
            'post_type' => 'product_variation',
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => -1,
            'meta_key' => '_abm_source_parent_id',
        ]);

        foreach (array_map('absint', $variations) as $variation_id) {
            $source_parent_id = absint(get_post_meta($variation_id, '_abm_source_parent_id', true));

            if (! $source_parent_id || empty($product_id_map[$source_parent_id])) {
                continue;
            }

            $target_parent_id = absint($product_id_map[$source_parent_id]);

            if ($target_parent_id && (int) get_post_field('post_parent', $variation_id) !== $target_parent_id) {
                wp_update_post([
                    'ID' => $variation_id,
                    'post_parent' => $target_parent_id,
                ]);
                $updated++;
            }
        }

        return $updated;
    }

    public function remapTermParents(array $term_id_map, array $term_taxonomy_id_map = []): int
    {
        if ([] === $term_id_map && [] === $term_taxonomy_id_map) {
            return 0;
        }

        $updated = 0;

        foreach ($this->targetTermIdsForParentRemap($term_id_map, $term_taxonomy_id_map) as $target_term_id) {
            $target_term_id = absint($target_term_id);
            $source_parent_id = absint(get_term_meta($target_term_id, '_abm_source_parent_term_id', true));
            $source_parent_term_taxonomy_id = absint(get_term_meta($target_term_id, '_abm_source_parent_term_taxonomy_id', true));

            if (! $target_term_id || (! $source_parent_id && ! $source_parent_term_taxonomy_id)) {
                continue;
            }

            $term = get_term($target_term_id);
            $target_parent_id = $this->mappedParentTermId($term && ! is_wp_error($term) ? $term->taxonomy : '', $source_parent_id, $source_parent_term_taxonomy_id, $term_id_map, $term_taxonomy_id_map);

            if (is_wp_error($term) || ! $term || ! $target_parent_id || absint($term->parent) === $target_parent_id) {
                continue;
            }

            $result = wp_update_term($target_term_id, $term->taxonomy, ['parent' => $target_parent_id]);

            if (! is_wp_error($result)) {
                $updated++;
            }
        }

        return $updated;
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

    private function mappedProductId(int $source_id, array $product_id_map): int
    {
        if (! $source_id) {
            return 0;
        }

        if (! empty($product_id_map[$source_id])) {
            return absint($product_id_map[$source_id]);
        }

        return $this->findExisting($source_id);
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

    private function importTerms(int $product_id, array $terms, array &$term_id_map, array &$term_taxonomy_id_map): int
    {
        $relationships = 0;

        foreach ($terms as $taxonomy => $items) {
            $taxonomy = sanitize_key($taxonomy);

            if (! taxonomy_exists($taxonomy)) {
                continue;
            }

            $term_taxonomy_ids = [];

            foreach ((array) $items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $name = sanitize_text_field($item['name'] ?? '');
                $slug = sanitize_title($item['slug'] ?? $name);

                if ('' === $name) {
                    continue;
                }

                $parent = $this->mappedParentTermId($taxonomy, absint($item['parent'] ?? 0), absint($item['parent_term_taxonomy_id'] ?? 0), $term_id_map, $term_taxonomy_id_map);
                $term = '' !== $slug ? term_exists($slug, $taxonomy) : null;

                if (! $term) {
                    $term = term_exists($name, $taxonomy);
                }

                if (! $term) {
                    $term = wp_insert_term($name, $taxonomy, [
                        'slug' => $slug,
                        'description' => sanitize_textarea_field($item['description'] ?? ''),
                        'parent' => $parent,
                    ]);
                } elseif ($parent) {
                    $term_id = absint(is_array($term) ? $term['term_id'] : $term);
                    wp_update_term($term_id, $taxonomy, ['parent' => $parent]);
                }

                if (! is_wp_error($term)) {
                    $term_id = absint(is_array($term) ? $term['term_id'] : $term);
                    $source_term_id = absint($item['term_id'] ?? 0);
                    $source_term_taxonomy_id = absint($item['term_taxonomy_id'] ?? 0);
                    $source_parent_id = absint($item['parent'] ?? 0);
                    $source_parent_term_taxonomy_id = absint($item['parent_term_taxonomy_id'] ?? 0);
                    $target_term_taxonomy_id = $this->termTaxonomyId($term_id, $taxonomy);

                    if ($source_term_id) {
                        $term_id_map[$source_term_id] = $term_id;
                        update_term_meta($term_id, '_abm_source_term_id', $source_term_id);
                    }

                    update_term_meta($term_id, '_abm_source_parent_term_id', $source_parent_id);
                    update_term_meta($term_id, '_abm_source_parent_term_taxonomy_id', $source_parent_term_taxonomy_id);

                    if ($source_term_taxonomy_id && $target_term_taxonomy_id) {
                        $term_taxonomy_id_map[$source_term_taxonomy_id] = $target_term_taxonomy_id;
                        update_term_meta($term_id, '_abm_source_term_taxonomy_id', $source_term_taxonomy_id);
                    }

                    if (! array_key_exists('assigned', $item) || ! empty($item['assigned'])) {
                        $term_taxonomy_ids[] = $target_term_taxonomy_id;
                    }
                }
            }

            $relationships += $this->setObjectTermRelationships($product_id, $taxonomy, $term_taxonomy_ids);
        }

        return $relationships;
    }

    private function mappedParentTermId(string $taxonomy, int $source_parent_id, int $source_parent_term_taxonomy_id, array $term_id_map, array $term_taxonomy_id_map): int
    {
        if ('' === $taxonomy || (! $source_parent_id && ! $source_parent_term_taxonomy_id)) {
            return 0;
        }

        if (! empty($term_taxonomy_id_map[$source_parent_term_taxonomy_id])) {
            $term = $this->getTermByTermTaxonomyId(absint($term_taxonomy_id_map[$source_parent_term_taxonomy_id]), $taxonomy);

            if ($term && ! is_wp_error($term)) {
                return absint($term->term_id);
            }
        }

        if ($source_parent_term_taxonomy_id) {
            $matches = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'fields' => 'ids',
                'number' => 1,
                'meta_key' => '_abm_source_term_taxonomy_id',
                'meta_value' => $source_parent_term_taxonomy_id,
            ]);

            if (! is_wp_error($matches) && ! empty($matches)) {
                return absint($matches[0]);
            }
        }

        if (! empty($term_id_map[$source_parent_id]) && $this->termTaxonomyMatches(absint($term_id_map[$source_parent_id]), $taxonomy, absint($term_taxonomy_id_map[$source_parent_term_taxonomy_id] ?? 0))) {
            return absint($term_id_map[$source_parent_id]);
        }

        $matches = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'ids',
            'number' => 1,
            'meta_key' => '_abm_source_term_id',
            'meta_value' => $source_parent_id,
        ]);

        return is_wp_error($matches) || empty($matches) ? 0 : absint($matches[0]);
    }

    private function targetTermIdsFromMaps(array $term_id_map, array $term_taxonomy_id_map): array
    {
        $term_ids = array_values(array_filter(array_map('absint', $term_id_map)));

        foreach (array_filter(array_map('absint', $term_taxonomy_id_map)) as $term_taxonomy_id) {
            $term = $this->getTermByTermTaxonomyId($term_taxonomy_id);

            if ($term && ! is_wp_error($term)) {
                $term_ids[] = absint($term->term_id);
            }
        }

        return array_values(array_unique(array_filter($term_ids)));
    }

    private function targetTermIdsForParentRemap(array $term_id_map, array $term_taxonomy_id_map): array
    {
        $term_ids = $this->targetTermIdsFromMaps($term_id_map, $term_taxonomy_id_map);
        $taxonomies = get_taxonomies([], 'names');

        foreach (array_filter(array_map('absint', array_keys($term_id_map))) as $source_term_id) {
            $matches = get_terms([
                'taxonomy' => $taxonomies,
                'hide_empty' => false,
                'fields' => 'ids',
                'meta_key' => '_abm_source_parent_term_id',
                'meta_value' => $source_term_id,
            ]);

            if (! is_wp_error($matches)) {
                $term_ids = array_merge($term_ids, array_map('absint', $matches));
            }
        }

        foreach (array_filter(array_map('absint', array_keys($term_taxonomy_id_map))) as $source_term_taxonomy_id) {
            $matches = get_terms([
                'taxonomy' => $taxonomies,
                'hide_empty' => false,
                'fields' => 'ids',
                'meta_key' => '_abm_source_parent_term_taxonomy_id',
                'meta_value' => $source_term_taxonomy_id,
            ]);

            if (! is_wp_error($matches)) {
                $term_ids = array_merge($term_ids, array_map('absint', $matches));
            }
        }

        return array_values(array_unique(array_filter($term_ids)));
    }

    private function termTaxonomyId(int $term_id, string $taxonomy): int
    {
        $term = get_term($term_id, $taxonomy);

        return is_wp_error($term) || ! $term ? 0 : absint($term->term_taxonomy_id);
    }

    private function termTaxonomyMatches(int $term_id, string $taxonomy, int $expected_term_taxonomy_id): bool
    {
        if (! $expected_term_taxonomy_id) {
            return true;
        }

        return $this->termTaxonomyId($term_id, $taxonomy) === $expected_term_taxonomy_id;
    }

    private function getTermByTermTaxonomyId(int $term_taxonomy_id, string $taxonomy = '')
    {
        global $wpdb;

        if (! $term_taxonomy_id) {
            return false;
        }

        $term_id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $term_taxonomy_id)
        );

        if (! $term_id) {
            return false;
        }

        $term = '' === $taxonomy ? get_term($term_id) : get_term($term_id, $taxonomy);

        return is_wp_error($term) ? false : $term;
    }

    private function setObjectTermRelationships(int $product_id, string $taxonomy, array $term_taxonomy_ids): int
    {
        global $wpdb;

        $term_taxonomy_ids = array_values(array_unique(array_filter(array_map('absint', $term_taxonomy_ids))));

        if ([] === $term_taxonomy_ids) {
            wp_set_object_terms($product_id, [], $taxonomy);
            return 0;
        }

        $target_term_ids = [];
        $target_term_taxonomy_ids = [];

        foreach ($term_taxonomy_ids as $term_taxonomy_id) {
            $term = $this->getTermByTermTaxonomyId($term_taxonomy_id, $taxonomy);

            if ($term && ! is_wp_error($term)) {
                $target_term_ids[] = absint($term->term_id);
                $target_term_taxonomy_ids[] = $term_taxonomy_id;
            }
        }

        wp_set_object_terms($product_id, $target_term_ids, $taxonomy);

        if ([] === $target_term_taxonomy_ids) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id IN (" . implode(',', array_fill(0, count($target_term_taxonomy_ids), '%d')) . ')',
                array_merge([$product_id], $target_term_taxonomy_ids)
            )
        );
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
