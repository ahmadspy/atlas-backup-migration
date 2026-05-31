<?php
/**
 * Admin settings page.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Admin;

use AtlasBackupMigration\Ajax\BackupAjaxController;
use AtlasBackupMigration\Ajax\ManagementAjaxController;

if (! defined('ABSPATH')) {
    exit;
}

final class SettingsPage
{
    private const OPTION_NAME = 'abm_settings';
    private const PAGE_SLUG = 'atlas-backup-migration';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('plugin_action_links_' . ABM_BASENAME, [$this, 'add_settings_link']);
    }

    public function add_menu_page(): void
    {
        add_menu_page(
            __('Atlas Backup', 'atlas-backup-migration'),
            __('Atlas Backup', 'atlas-backup-migration'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-database-export',
            58
        );

        add_submenu_page(
            self::PAGE_SLUG,
            __('Settings', 'atlas-backup-migration'),
            __('Settings', 'atlas-backup-migration'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'abm_settings_group',
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => $this->default_settings(),
            ]
        );
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if ('toplevel_page_' . self::PAGE_SLUG !== $hook_suffix) {
            return;
        }

        wp_enqueue_style(
            'abm-admin',
            ABM_URL . 'assets/css/admin.css',
            [],
            ABM_VERSION
        );

        wp_enqueue_script(
            'abm-admin',
            ABM_URL . 'assets/js/admin.js',
            ['jquery'],
            ABM_VERSION,
            true
        );

        wp_localize_script(
            'abm-admin',
            'abmBackup',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(BackupAjaxController::nonceAction()),
                'managementNonce' => wp_create_nonce(ManagementAjaxController::nonceAction()),
                'syncNonce' => wp_create_nonce('abm_sync_nonce'),
                'restBase' => rest_url('atlas-backup-migration/v1'),
                'themes' => $this->theme_choices(),
                'i18n' => [
                    'starting' => __('Starting backup...', 'atlas-backup-migration'),
                    'failed' => __('Backup failed. Please review the error details.', 'atlas-backup-migration'),
                    'completed' => __('Backup completed successfully.', 'atlas-backup-migration'),
                    'loading' => __('Loading...', 'atlas-backup-migration'),
                    'deleted' => __('Backup deleted.', 'atlas-backup-migration'),
                    'exporting' => __('Creating granular export...', 'atlas-backup-migration'),
                    'importing' => __('Importing package...', 'atlas-backup-migration'),
                    'confirmDelete' => __('Delete this backup package?', 'atlas-backup-migration'),
                    'tokenReady' => __('Sync token generated. It expires in 4 hours.', 'atlas-backup-migration'),
                ],
            ]
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'atlas-backup-migration'));
        }

        $settings = wp_parse_args(
            get_option(self::OPTION_NAME, []),
            $this->default_settings()
        );

        require ABM_PATH . 'templates/admin/settings-page.php';
    }

    public function sanitize_settings($input): array
    {
        $defaults = $this->default_settings();
        $input = is_array($input) ? $input : [];

        return [
            'backup_location' => in_array($input['backup_location'] ?? '', ['local', 'cloud', 'both'], true)
                ? $input['backup_location']
                : $defaults['backup_location'],
            'retention_days' => max(1, min(365, absint($input['retention_days'] ?? $defaults['retention_days']))),
            'email_notifications' => ! empty($input['email_notifications']),
        ];
    }

    public function add_settings_link(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)),
            esc_html__('Settings', 'atlas-backup-migration')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    private function default_settings(): array
    {
        return [
            'backup_location' => 'local',
            'retention_days' => 14,
            'email_notifications' => true,
        ];
    }

    private function theme_choices(): array
    {
        $themes = [];

        foreach (wp_get_themes() as $slug => $theme) {
            $themes[] = [
                'slug' => sanitize_key($slug),
                'name' => $theme->get('Name'),
                'active' => get_stylesheet() === $slug,
            ];
        }

        return $themes;
    }
}
