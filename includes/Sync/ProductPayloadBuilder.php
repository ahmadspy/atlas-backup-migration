<?php
/**
 * Builds safe product payloads for REST sync.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Sync;

if (! defined('ABSPATH')) {
    exit;
}

final class ProductPayloadBuilder
{
    /**
     * Builds a portable product or variation payload with parent and taxonomy IDs.
     *
     * @param int $product_id Product or variation ID.
     * @return array
     */
    public function build(int $product_id): array
    {
        $post = get_post($product_id);

        if (! $post || ! in_array($post->post_type, ['product', 'product_variation'], true)) {
            return [];
        }

        $attachment_ids = $this->attachmentIds($product_id);
        $media = [];

        foreach ($attachment_ids as $attachment_id) {
            $media[] = $this->attachmentPayload($attachment_id);
        }

        return [
            'source_id' => $product_id,
            'post' => [
                'post_type' => $post->post_type,
                'post_status' => $post->post_status,
                'post_title' => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_name' => $post->post_name,
                'post_parent' => (int) $post->post_parent,
                'menu_order' => (int) $post->menu_order,
            ],
            'meta' => $this->safeMeta($product_id),
            'terms' => $this->terms($product_id),
            'media' => $media,
            'checksum' => hash('sha256', wp_json_encode([$post->post_title, $post->post_modified_gmt, $product_id])),
        ];
    }

    /**
     * Returns post meta safe for transport.
     *
     * @param int $post_id Post ID.
     * @return array
     */
    private function safeMeta(int $post_id): array
    {
        $meta = get_post_meta($post_id);
        $blocked = ['_edit_lock', '_edit_last'];
        $safe = [];

        foreach ($meta as $key => $values) {
            if (in_array($key, $blocked, true)) {
                continue;
            }

            $safe[$key] = array_map('maybe_unserialize', (array) $values);
        }

        return $safe;
    }

    /**
     * Returns assigned taxonomy terms and ancestors.
     *
     * @param int $post_id Post ID.
     * @return array
     */
    private function terms(int $post_id): array
    {
        $taxonomies = get_object_taxonomies((string) get_post_type($post_id));
        $terms = [];

        foreach ($taxonomies as $taxonomy) {
            $post_terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'all']);

            if (is_wp_error($post_terms)) {
                continue;
            }

            $terms[$taxonomy] = $this->termPayloadsWithAncestors($post_terms, $taxonomy);
        }

        return $terms;
    }

    /**
     * Adds assigned terms plus ancestors so parent/child taxonomies can be rebuilt.
     *
     * @param array  $post_terms Assigned term objects.
     * @param string $taxonomy Taxonomy name.
     * @return array
     */
    private function termPayloadsWithAncestors(array $post_terms, string $taxonomy): array
    {
        $items = [];
        $assigned_ids = [];

        foreach ($post_terms as $term) {
            if (! $term || is_wp_error($term)) {
                continue;
            }

            $assigned_ids[] = absint($term->term_id);
            $items[absint($term->term_id)] = $this->termPayload($term, true);

            foreach (get_ancestors(absint($term->term_id), $taxonomy, 'taxonomy') as $ancestor_id) {
                $ancestor = get_term(absint($ancestor_id), $taxonomy);

                if ($ancestor && ! is_wp_error($ancestor)) {
                    $items[absint($ancestor->term_id)] = $this->termPayload($ancestor, in_array(absint($ancestor->term_id), $assigned_ids, true));
                }
            }
        }

        return array_values($items);
    }

    /**
     * Builds a taxonomy term payload with term_id, term_taxonomy_id, and parent IDs.
     *
     * @param \WP_Term $term Term object.
     * @param bool     $assigned Whether the source post is directly assigned to this term.
     * @return array
     */
    private function termPayload($term, bool $assigned): array
    {
        $parent = absint($term->parent);

        return [
            'term_id' => (int) $term->term_id,
            'term_taxonomy_id' => (int) $term->term_taxonomy_id,
            'parent' => $parent,
            'parent_term_taxonomy_id' => $parent ? $this->termTaxonomyId($parent, $term->taxonomy) : 0,
            'taxonomy' => $term->taxonomy,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'assigned' => $assigned,
        ];
    }

    /**
     * Looks up term_taxonomy_id for a source term.
     *
     * @param int    $term_id Term ID.
     * @param string $taxonomy Taxonomy name.
     * @return int
     */
    private function termTaxonomyId(int $term_id, string $taxonomy): int
    {
        $term = get_term($term_id, $taxonomy);

        return is_wp_error($term) || ! $term ? 0 : absint($term->term_taxonomy_id);
    }

    /**
     * Returns source product attachment IDs.
     *
     * @param int $product_id Product ID.
     * @return array
     */
    private function attachmentIds(int $product_id): array
    {
        $ids = [];
        $thumbnail_id = absint(get_post_meta($product_id, '_thumbnail_id', true));

        if ($thumbnail_id) {
            $ids[] = $thumbnail_id;
        }

        $gallery = (string) get_post_meta($product_id, '_product_image_gallery', true);

        foreach (array_filter(array_map('absint', explode(',', $gallery))) as $attachment_id) {
            $ids[] = $attachment_id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Builds source attachment metadata for packaging or streaming.
     *
     * @param int $attachment_id Attachment ID.
     * @return array
     */
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
            'download_url' => rest_url('atlas-backup-migration/v1/sync/media-chunk/' . $attachment_id),
            'chunk_size' => 262144,
        ];
    }
}
