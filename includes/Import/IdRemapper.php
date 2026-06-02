<?php
/**
 * Remaps source IDs to destination IDs inside imported payloads.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Import;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Recursively remaps imported IDs in scalar, array, JSON, and serialized payloads.
 */
final class IdRemapper
{
    /**
     * Remaps every matching scalar ID in a value using a single source-to-target map.
     *
     * @param mixed $value Value to remap.
     * @param array $id_map Source-to-target ID map.
     * @return mixed
     */
    public function remapValue($value, array $id_map)
    {
        if ([] === $id_map) {
            return $value;
        }

        if (is_int($value)) {
            return $id_map[$value] ?? $value;
        }

        if (is_string($value)) {
            return $this->remapString($value, $id_map);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->remapValue($item, $id_map);
            }

            return $value;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = $this->remapValue($item, $id_map);
            }
        }

        return $value;
    }

    /**
     * Remaps Elementor JSON values while refreshing attachment URLs where possible.
     *
     * @param mixed $value Elementor JSON string.
     * @param array $id_map Source-to-target attachment ID map.
     * @return mixed
     */
    public function remapElementorJson($value, array $id_map)
    {
        if (! is_string($value) || '' === $value || [] === $id_map) {
            return $value;
        }

        $decoded = $this->decodeJsonString($value);

        if (! is_array($decoded)) {
            return $this->remapString($value, $id_map);
        }

        $decoded = $this->remapElementorNode($decoded, $id_map);
        $encoded = wp_json_encode($decoded);

        return false === $encoded ? $value : $encoded;
    }

    /**
     * Remaps comma-separated ID strings.
     *
     * @param string $value CSV source IDs.
     * @param array  $id_map Source-to-target ID map.
     * @return string
     */
    public function remapCsvIds(string $value, array $id_map): string
    {
        $ids = array_filter(array_map('absint', explode(',', $value)));
        $mapped = [];

        foreach ($ids as $id) {
            $mapped[] = absint($id_map[$id] ?? $id);
        }

        return implode(',', array_values(array_unique(array_filter($mapped))));
    }

    /**
     * Remaps known ID fields by semantic context, including taxonomy and parent IDs.
     *
     * @param mixed  $value Value to inspect.
     * @param array  $maps Maps keyed by media, product, term, and term_taxonomy.
     * @param string $context_key Root field or meta key.
     * @return mixed
     */
    public function remapKnownIds($value, array $maps, string $context_key = '')
    {
        $maps = [
            'media' => is_array($maps['media'] ?? null) ? $maps['media'] : [],
            'product' => is_array($maps['product'] ?? null) ? $maps['product'] : [],
            'term' => is_array($maps['term'] ?? null) ? $maps['term'] : [],
            'term_taxonomy' => is_array($maps['term_taxonomy'] ?? null) ? $maps['term_taxonomy'] : [],
        ];

        return $this->remapKnownIdsInValue($value, $maps, $context_key);
    }

    /**
     * Remaps IDs inside a value using the map implied by the current field key.
     *
     * @param mixed  $value Value to inspect.
     * @param array  $maps Normalized contextual maps.
     * @param string $context_key Field key describing the value.
     * @return mixed
     */
    private function remapKnownIdsInValue($value, array $maps, string $context_key)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $child_key = is_int($key) || ctype_digit((string) $key) ? $context_key : (string) $key;
                $value[$key] = $this->remapKnownIdsInValue($item, $maps, $child_key);
            }

            return $value;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = $this->remapKnownIdsInValue($item, $maps, (string) $key);
            }

            return $value;
        }

        $id_map = $this->mapForContextKey($context_key, $maps);

        if (is_int($value)) {
            return [] === $id_map ? $value : ($id_map[$value] ?? $value);
        }

        if (is_string($value)) {
            $structured = $this->remapKnownIdsInStructuredString($value, $maps);

            if ($structured !== $value) {
                return $structured;
            }

            return [] === $id_map ? $value : $this->remapString($value, $id_map);
        }

        return $value;
    }

    /**
     * Remaps contextual IDs inside JSON or serialized strings.
     *
     * @param string $value Structured string value.
     * @param array  $maps Normalized contextual maps.
     * @return string
     */
    private function remapKnownIdsInStructuredString(string $value, array $maps): string
    {
        if ('' === $value) {
            return $value;
        }

        $decoded = $this->decodeJsonString($value);

        if (is_array($decoded)) {
            $encoded = wp_json_encode($this->remapKnownIdsInValue($decoded, $maps, ''));

            return false === $encoded ? $value : $encoded;
        }

        $unserialized = @unserialize($value, ['allowed_classes' => false]);

        if (false !== $unserialized || 'b:0;' === $value) {
            return serialize($this->remapKnownIdsInValue($unserialized, $maps, ''));
        }

        return $value;
    }

    /**
     * Selects the correct contextual ID map for a field key.
     *
     * @param string $key Field or meta key.
     * @param array  $maps Normalized contextual maps.
     * @return array
     */
    private function mapForContextKey(string $key, array $maps): array
    {
        $key = strtolower(str_replace('-', '_', $key));

        if ('' === $key) {
            return [];
        }

        if (false !== strpos($key, 'term_taxonomy_id') || false !== strpos($key, 'term_taxonomy_ids') || false !== strpos($key, 'term_relationship')) {
            return $maps['term_taxonomy'];
        }

        if (
            false !== strpos($key, 'term_id')
            || false !== strpos($key, 'term_ids')
            || false !== strpos($key, 'parent_term')
            || false !== strpos($key, 'category')
            || false !== strpos($key, 'tag')
            || false !== strpos($key, 'taxonomy')
            || false !== strpos($key, 'attribute')
        ) {
            return $maps['term'];
        }

        if (
            'post_parent' === $key
            || false !== strpos($key, 'product_id')
            || false !== strpos($key, 'product_ids')
            || false !== strpos($key, 'parent_product')
            || false !== strpos($key, 'variation_id')
            || false !== strpos($key, 'variation_ids')
        ) {
            return $maps['product'];
        }

        if (
            false !== strpos($key, 'attachment_id')
            || false !== strpos($key, 'attachment_ids')
            || false !== strpos($key, 'thumbnail_id')
            || false !== strpos($key, 'image_id')
            || false !== strpos($key, 'image_ids')
            || false !== strpos($key, 'gallery')
            || false !== strpos($key, 'media')
        ) {
            return $maps['media'];
        }

        return [];
    }

    /**
     * Remaps a scalar string, CSV list, JSON string, or serialized payload.
     *
     * @param string $value Value to remap.
     * @param array  $id_map Source-to-target ID map.
     * @return string
     */
    private function remapString(string $value, array $id_map): string
    {
        if ('' === $value) {
            return $value;
        }

        if (isset($id_map[absint($value)]) && (string) absint($value) === $value) {
            return (string) $id_map[absint($value)];
        }

        if (preg_match('/^\d+(,\d+)*$/', $value)) {
            return $this->remapCsvIds($value, $id_map);
        }

        $decoded = $this->decodeJsonString($value);

        if (is_array($decoded)) {
            $encoded = wp_json_encode($this->remapValue($decoded, $id_map));

            return false === $encoded ? $value : $encoded;
        }

        $unserialized = @unserialize($value, ['allowed_classes' => false]);

        if (false !== $unserialized || 'b:0;' === $value) {
            return serialize($this->remapValue($unserialized, $id_map));
        }

        return $value;
    }

    /**
     * Recursively remaps IDs in Elementor node arrays.
     *
     * @param array $node Elementor node.
     * @param array $id_map Source-to-target attachment ID map.
     * @return array
     */
    private function remapElementorNode(array $node, array $id_map): array
    {
        if (isset($node['id']) && is_numeric($node['id'])) {
            $source_id = absint($node['id']);

            if ($source_id && isset($id_map[$source_id])) {
                $new_id = absint($id_map[$source_id]);
                $node['id'] = is_string($node['id']) ? (string) $new_id : $new_id;

                if (array_key_exists('url', $node)) {
                    $new_url = wp_get_attachment_url($new_id);

                    if (is_string($new_url) && '' !== $new_url) {
                        $node['url'] = $new_url;
                    }
                }
            }
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->remapElementorNode($value, $id_map);
                continue;
            }

            if ('id' === $key) {
                continue;
            }

            if (is_string($value)) {
                $node[$key] = $this->shouldRemapElementorScalar((string) $key)
                    ? $this->remapString($value, $id_map)
                    : $this->remapStructuredString($value, $id_map);
            }
        }

        return $node;
    }

    /**
     * Determines whether an Elementor scalar key should use direct ID remapping.
     *
     * @param string $key Elementor field key.
     * @return bool
     */
    private function shouldRemapElementorScalar(string $key): bool
    {
        $key = strtolower($key);

        return 'id' === $key
            || '_id' === substr($key, -3)
            || false !== strpos($key, 'image')
            || false !== strpos($key, 'media')
            || false !== strpos($key, 'attachment')
            || false !== strpos($key, 'gallery')
            || false !== strpos($key, 'thumbnail');
    }

    /**
     * Remaps JSON or serialized structured strings without changing plain text.
     *
     * @param string $value Structured string.
     * @param array  $id_map Source-to-target ID map.
     * @return string
     */
    private function remapStructuredString(string $value, array $id_map): string
    {
        $decoded = $this->decodeJsonString($value);

        if (is_array($decoded)) {
            $encoded = wp_json_encode($this->remapElementorNode($decoded, $id_map));

            return false === $encoded ? $value : $encoded;
        }

        $unserialized = @unserialize($value, ['allowed_classes' => false]);

        if (false !== $unserialized || 'b:0;' === $value) {
            return serialize($this->remapValue($unserialized, $id_map));
        }

        return $value;
    }

    /**
     * Decodes plain or slashed JSON into an array.
     *
     * @param string $value JSON string.
     * @return array|null
     */
    private function decodeJsonString(string $value)
    {
        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $unslashed = wp_unslash($value);

        if ($unslashed !== $value) {
            $decoded = json_decode($unslashed, true);
        }

        return is_array($decoded) ? $decoded : null;
    }
}
