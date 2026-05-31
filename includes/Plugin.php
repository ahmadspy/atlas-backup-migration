<?php
/**
 * Main plugin bootstrap.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration;

use AtlasBackupMigration\Ajax\BackupAjaxController;
use AtlasBackupMigration\Ajax\ManagementAjaxController;
use AtlasBackupMigration\Admin\SettingsPage;
use AtlasBackupMigration\Sync\RestSyncController;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?Plugin $instance = null;

    private SettingsPage $settings_page;

    private BackupAjaxController $backup_ajax_controller;

    private ManagementAjaxController $management_ajax_controller;

    private RestSyncController $rest_sync_controller;

    public static function instance(): Plugin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        if (false === get_option('abm_settings')) {
            add_option('abm_settings', [
                'backup_location' => 'local',
                'retention_days' => 14,
                'email_notifications' => true,
            ]);
        }
    }

    public static function deactivate(): void
    {
    }

    public function boot(): void
    {
        $this->load_textdomain();
        $this->load_dependencies();
        $this->register_services();
    }

    private function load_textdomain(): void
    {
        load_plugin_textdomain(
            'atlas-backup-migration',
            false,
            dirname(ABM_BASENAME) . '/languages'
        );
    }

    private function load_dependencies(): void
    {
        require_once ABM_PATH . 'includes/Admin/SettingsPage.php';
        require_once ABM_PATH . 'includes/Ajax/BackupAjaxController.php';
        require_once ABM_PATH . 'includes/Ajax/ManagementAjaxController.php';
        require_once ABM_PATH . 'includes/Backup/BackupJob.php';
        require_once ABM_PATH . 'includes/Backup/BackupRepository.php';
        require_once ABM_PATH . 'includes/Backup/DatabaseDumper.php';
        require_once ABM_PATH . 'includes/Backup/FileScanner.php';
        require_once ABM_PATH . 'includes/Backup/InstallerGenerator.php';
        require_once ABM_PATH . 'includes/Backup/ZipPackager.php';
        require_once ABM_PATH . 'includes/Compatibility/CompatibilityModule.php';
        require_once ABM_PATH . 'includes/Compatibility/DokanCompatibility.php';
        require_once ABM_PATH . 'includes/Compatibility/ElementorCompatibility.php';
        require_once ABM_PATH . 'includes/Compatibility/WooCommerceCompatibility.php';
        require_once ABM_PATH . 'includes/Export/GranularExportService.php';
        require_once ABM_PATH . 'includes/Import/IdRemapper.php';
        require_once ABM_PATH . 'includes/Import/SmartImporterService.php';
        require_once ABM_PATH . 'includes/Sync/AuthTokenService.php';
        require_once ABM_PATH . 'includes/Sync/MediaChunkStore.php';
        require_once ABM_PATH . 'includes/Sync/ProductImporter.php';
        require_once ABM_PATH . 'includes/Sync/ProductPayloadBuilder.php';
        require_once ABM_PATH . 'includes/Sync/RestSyncController.php';
    }

    private function register_services(): void
    {
        if (is_admin()) {
            $this->settings_page = new SettingsPage();
            $this->settings_page->register();

            $this->backup_ajax_controller = new BackupAjaxController();
            $this->backup_ajax_controller->register();

            $this->management_ajax_controller = new ManagementAjaxController();
            $this->management_ajax_controller->register();
        }

        $this->rest_sync_controller = new RestSyncController();
        $this->rest_sync_controller->register();
    }
}
