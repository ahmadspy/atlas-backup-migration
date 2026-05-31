<?php
/**
 * WooCommerce compatibility rules.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Compatibility;

use wpdb;

if (! defined('ABSPATH')) {
    exit;
}

final class WooCommerceCompatibility
{
    private wpdb $wpdb;

    public function __construct(wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function manifest(string $upload_relative_base): array
    {
        $active = $this->isActive();

        return [
            'active' => $active,
            'version' => defined('WC_VERSION') ? WC_VERSION : '',
            'tables' => $active ? $this->tables() : [],
            'meta_keys' => [
                '_thumbnail_id',
                '_product_image_gallery',
                '_wc_attachment_source',
                '_downloadable_files',
                '_product_attributes',
            ],
            'product_media' => $active ? $this->productMedia($upload_relative_base) : [],
            'required_file_prefixes' => $active ? [
                $upload_relative_base,
                $upload_relative_base . '/woocommerce_uploads',
            ] : [],
            'url_rewrite_targets' => [
                'postmeta:_thumbnail_id',
                'postmeta:_product_image_gallery',
                'postmeta:_downloadable_files',
                'posts:post_content',
            ],
        ];
    }

    private function isActive(): bool
    {
        return class_exists('WooCommerce') || function_exists('WC') || defined('WC_VERSION');
    }

    private function tables(): array
    {
        $suffixes = [
            'woocommerce_api_keys',
            'woocommerce_attribute_taxonomies',
            'woocommerce_downloadable_product_permissions',
            'woocommerce_log',
            'woocommerce_order_itemmeta',
            'woocommerce_order_items',
            'woocommerce_payment_tokenmeta',
            'woocommerce_payment_tokens',
            'woocommerce_sessions',
            'woocommerce_shipping_zone_locations',
            'woocommerce_shipping_zone_methods',
            'woocommerce_shipping_zones',
            'woocommerce_tax_rate_locations',
            'woocommerce_tax_rates',
            'wc_admin_note_actions',
            'wc_admin_notes',
            'wc_category_lookup',
            'wc_customer_lookup',
            'wc_download_log',
            'wc_order_addresses',
            'wc_order_coupon_lookup',
            'wc_order_operational_data',
            'wc_order_product_lookup',
            'wc_order_stats',
            'wc_order_tax_lookup',
            'wc_orders',
            'wc_orders_meta',
            'wc_product_attributes_lookup',
            'wc_product_download_directories',
            'wc_product_meta_lookup',
            'wc_rate_limits',
            'wc_reserved_stock',
            'wc_tax_rate_classes',
            'wc_webhooks',
        ];

        return $this->existingTables($suffixes);
    }

    private function productMedia(string $upload_relative_base): array
    {
        $product_ids = $this->wpdb->get_col(
            "SELECT ID FROM {$this->wpdb->posts} WHERE post_type IN ('product', 'product_variation') AND post_status NOT IN ('trash', 'auto-draft')"
        );

        $items = [];

        foreach (array_map('absint', is_array($product_ids) ? $product_ids : []) as $product_id) {
            $attachment_ids = [];
            $thumbnail_id = absint(get_post_meta($product_id, '_thumbnail_id', true));

            if ($thumbnail_id) {
                $attachment_ids[] = $thumbnail_id;
            }

            $gallery = (string) get_post_meta($product_id, '_product_image_gallery', true);

            foreach (array_filter(array_map('absint', explode(',', $gallery))) as $attachment_id) {
                $attachment_ids[] = $attachment_id;
            }

            $relative_files = [];

            foreach (array_unique($attachment_ids) as $attachment_id) {
                $relative_files = array_merge($relative_files, $this->attachmentFiles($attachment_id, $upload_relative_base));
            }

            $items[] = [
                'product_id' => $product_id,
                'attachment_ids' => array_values(array_unique($attachment_ids)),
                'relative_files' => array_values(array_unique($relative_files)),
            ];
        }

        return $items;
    }

    private function attachmentFiles(int $attachment_id, string $upload_relative_base): array
    {
        $files = [];
        $main = get_post_meta($attachment_id, '_wp_attached_file', true);
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (is_string($main) && '' !== $main) {
            $files[] = trailingslashit($upload_relative_base) . ltrim($main, '/');
        }

        if (is_array($metadata) && ! empty($metadata['sizes']) && is_string($main)) {
            $directory = trim(dirname($main), './');

            foreach ($metadata['sizes'] as $size) {
                if (! empty($size['file'])) {
                    $files[] = trailingslashit($upload_relative_base) . ('' === $directory ? '' : trailingslashit($directory)) . ltrim((string) $size['file'], '/');
                }
            }
        }

        return array_values(array_unique(array_filter($files)));
    }

    private function existingTables(array $suffixes): array
    {
        $tables = [];

        foreach ($suffixes as $suffix) {
            $table = $this->wpdb->prefix . $suffix;

            if ($this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
                $tables[] = $table;
            }
        }

        return $tables;
    }
}
