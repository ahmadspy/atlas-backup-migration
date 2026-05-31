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

final class IdRemapper
{
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

    public function remapCsvIds(string $value, array $id_map): string
    {
        $ids = array_filter(array_map('absint', explode(',', $value)));
        $mapped = [];

        foreach ($ids as $id) {
            $mapped[] = absint($id_map[$id] ?? $id);
        }

        return implode(',', array_values(array_unique(array_filter($mapped))));
    }

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
