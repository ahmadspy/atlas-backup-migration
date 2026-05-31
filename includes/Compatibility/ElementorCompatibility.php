<?php
/**
 * Elementor compatibility rules.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Compatibility;

use wpdb;

if (! defined('ABSPATH')) {
    exit;
}

final class ElementorCompatibility
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
            'version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
            'tables' => [],
            'meta_keys' => [
                '_elementor_data',
                '_elementor_page_settings',
                '_elementor_css',
                '_elementor_controls_usage',
            ],
            'required_file_prefixes' => $active ? [
                $upload_relative_base . '/elementor',
                $upload_relative_base . '/elementor/css',
            ] : [],
            'restore_actions' => [
                'regenerate_css' => $active,
                'clear_cache' => $active,
            ],
            'url_rewrite_targets' => [
                'postmeta:_elementor_data',
                'postmeta:_elementor_page_settings',
                'postmeta:_elementor_css',
            ],
        ];
    }

    private function isActive(): bool
    {
        return did_action('elementor/loaded') || defined('ELEMENTOR_VERSION') || class_exists('\Elementor\Plugin');
    }
}
