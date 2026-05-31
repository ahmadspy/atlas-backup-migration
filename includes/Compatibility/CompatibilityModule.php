<?php
/**
 * Detects supported plugins and builds integrity rules for backups.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Compatibility;

use wpdb;

if (! defined('ABSPATH')) {
    exit;
}

final class CompatibilityModule
{
    private wpdb $wpdb;

    public function __construct(wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function buildManifest(): array
    {
        $upload_dir = wp_upload_dir(null, false);
        $base_upload = trailingslashit(wp_normalize_path($upload_dir['basedir']));
        $base_upload_relative = $this->relativeToRoot($base_upload);
        $plugins = [];

        $plugins['woocommerce'] = (new WooCommerceCompatibility($this->wpdb))->manifest($base_upload_relative);
        $plugins['dokan'] = (new DokanCompatibility($this->wpdb))->manifest($base_upload_relative);
        $plugins['elementor'] = (new ElementorCompatibility($this->wpdb))->manifest($base_upload_relative);

        return [
            'site_url' => site_url(),
            'home_url' => home_url(),
            'db_prefix' => $this->wpdb->prefix,
            'upload_baseurl' => $upload_dir['baseurl'] ?? '',
            'upload_basedir' => $base_upload_relative,
            'plugins' => $plugins,
            'url_rewrite' => [
                'columns' => [
                    'options' => ['option_value'],
                    'postmeta' => ['meta_value'],
                    'posts' => ['post_content', 'guid'],
                    'termmeta' => ['meta_value'],
                    'usermeta' => ['meta_value'],
                ],
                'serialized_safe' => true,
            ],
        ];
    }

    public function tablePriority(array $tables, array $manifest): array
    {
        $priority = [];

        foreach ($manifest['plugins'] ?? [] as $plugin) {
            if (empty($plugin['active']) || empty($plugin['tables'])) {
                continue;
            }

            foreach ($plugin['tables'] as $table) {
                $priority[] = $table;
            }
        }

        $priority = array_values(array_unique(array_intersect($priority, $tables)));
        $remaining = array_values(array_diff($tables, $priority));

        return array_merge($priority, $remaining);
    }

    public function requiredFilePrefixes(array $manifest): array
    {
        $prefixes = [];

        foreach ($manifest['plugins'] ?? [] as $plugin) {
            if (empty($plugin['active']) || empty($plugin['required_file_prefixes'])) {
                continue;
            }

            foreach ($plugin['required_file_prefixes'] as $prefix) {
                $prefixes[] = trim((string) $prefix, '/');
            }
        }

        return array_values(array_unique(array_filter($prefixes)));
    }

    public function productAttachmentFiles(array $manifest): array
    {
        $product_media = $manifest['plugins']['woocommerce']['product_media'] ?? [];
        $files = [];

        foreach ($product_media as $item) {
            foreach (($item['relative_files'] ?? []) as $file) {
                $files[] = ltrim((string) $file, '/');
            }
        }

        return array_values(array_unique(array_filter($files)));
    }

    private function relativeToRoot(string $absolute_path): string
    {
        $root = trailingslashit(wp_normalize_path(ABSPATH));
        $absolute_path = trailingslashit(wp_normalize_path($absolute_path));

        return trim(str_replace($root, '', $absolute_path), '/');
    }
}
