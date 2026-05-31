<?php
/**
 * Dokan compatibility rules.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Compatibility;

use wpdb;

if (! defined('ABSPATH')) {
    exit;
}

final class DokanCompatibility
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
            'version' => defined('DOKAN_PLUGIN_VERSION') ? DOKAN_PLUGIN_VERSION : '',
            'tables' => $active ? $this->tables() : [],
            'meta_keys' => [
                'dokan_profile_settings',
                'dokan_store_name',
                'dokan_enable_selling',
                '_dokan_vendor_id',
                '_dokan_commission_rate',
            ],
            'required_file_prefixes' => $active ? [
                $upload_relative_base . '/dokan',
                $upload_relative_base . '/dokan-store-support',
                $upload_relative_base,
            ] : [],
            'url_rewrite_targets' => [
                'usermeta:dokan_profile_settings',
                'postmeta:_dokan_vendor_id',
                'options:dokan_%',
            ],
        ];
    }

    private function isActive(): bool
    {
        return class_exists('WeDevs_Dokan') || function_exists('dokan') || defined('DOKAN_PLUGIN_VERSION');
    }

    private function tables(): array
    {
        $suffixes = [
            'dokan_orders',
            'dokan_refund',
            'dokan_vendor_balance',
            'dokan_withdraw',
            'dokan_announcement',
            'dokan_delivery_time',
            'dokan_follow_store_followers',
            'dokan_product_map',
            'dokan_report_abuse_reports',
            'dokan_shipping_tracking',
            'dokan_vendor_verification',
        ];

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
