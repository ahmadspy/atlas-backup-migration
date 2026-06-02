<?php
/**
 * AJAX controller for backup manager, granular export and smart import.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Ajax;

use AtlasBackupMigration\Backup\BackupRepository;
use AtlasBackupMigration\Export\GranularExportService;
use AtlasBackupMigration\Import\SmartImporterService;

if (! defined('ABSPATH')) {
    exit;
}

final class ManagementAjaxController
{
    private const NONCE_ACTION = 'abm_management_nonce';

    /**
     * Registers AJAX endpoints used by the backup management workspace.
     */
    public function register(): void
    {
        add_action('wp_ajax_abm_list_backups', [$this, 'listBackups']);
        add_action('wp_ajax_abm_delete_backup', [$this, 'deleteBackup']);
        add_action('wp_ajax_abm_create_granular_export', [$this, 'createGranularExport']);
        add_action('wp_ajax_abm_smart_import', [$this, 'smartImport']);
        add_action('wp_ajax_abm_prepare_smart_import', [$this, 'prepareSmartImport']);
        add_action('wp_ajax_abm_process_smart_import', [$this, 'processSmartImport']);
        add_action('wp_ajax_abm_cleanup_import', [$this, 'cleanupImport']);
    }

    /**
     * Returns the nonce action used for management requests.
     *
     * @return string
     */
    public static function nonceAction(): string
    {
        return self::NONCE_ACTION;
    }

    /**
     * Lists known backup packages.
     */
    public function listBackups(): void
    {
        $this->guard();
        wp_send_json_success(['items' => (new BackupRepository())->all()]);
    }

    /**
     * Deletes a backup package by job ID.
     */
    public function deleteBackup(): void
    {
        $this->guard();

        $job_id = isset($_POST['job_id']) ? sanitize_key(wp_unslash($_POST['job_id'])) : '';

        if ('' === $job_id) {
            wp_send_json_error(['message' => __('Missing backup job ID.', 'atlas-backup-migration')], 400);
        }

        if (! (new BackupRepository())->delete($job_id)) {
            wp_send_json_error(['message' => __('Backup could not be deleted.', 'atlas-backup-migration')], 500);
        }

        wp_send_json_success(['message' => __('Backup deleted.', 'atlas-backup-migration')]);
    }

    /**
     * Creates a focused granular export package.
     */
    public function createGranularExport(): void
    {
        $this->guard();

        $request = [
            'export_type' => isset($_POST['export_type']) ? sanitize_key(wp_unslash($_POST['export_type'])) : 'woocommerce',
            'theme_slug' => isset($_POST['theme_slug']) ? sanitize_key(wp_unslash($_POST['theme_slug'])) : '',
            'label' => isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '',
        ];

        $result = (new GranularExportService())->export($request);

        if (empty($result['success'])) {
            wp_send_json_error(['message' => $result['message'] ?? __('Granular export failed.', 'atlas-backup-migration')], 500);
        }

        wp_send_json_success($result);
    }

    /**
     * Runs the legacy synchronous smart importer.
     */
    public function smartImport(): void
    {
        $this->guard();

        $file = $_FILES['package'] ?? [];
        $result = (new SmartImporterService())->import(is_array($file) ? $file : []);

        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            $status = is_array($error_data) ? absint($error_data['status'] ?? 400) : 400;
            wp_send_json_error(['message' => $result->get_error_message()], $status);
        }

        wp_send_json_success($result);
    }

    /**
     * Prepares a resumable smart import session from an uploaded ZIP.
     */
    public function prepareSmartImport(): void
    {
        $this->guard();

        $file = $_FILES['package'] ?? [];
        $result = (new SmartImporterService())->prepare(is_array($file) ? $file : []);

        $this->sendImportResult($result);
    }

    /**
     * Processes the next chunk for a resumable smart import session.
     */
    public function processSmartImport(): void
    {
        $this->guard();

        $session_id = isset($_POST['session_id']) ? sanitize_key(wp_unslash($_POST['session_id'])) : '';
        $result = (new SmartImporterService())->process($session_id);

        $this->sendImportResult($result);
    }

    /**
     * Deletes temporary files for a smart import session.
     */
    public function cleanupImport(): void
    {
        $this->guard();

        $session_id = isset($_POST['session_id']) ? sanitize_key(wp_unslash($_POST['session_id'])) : '';
        $result = (new SmartImporterService())->cleanupSession($session_id);

        $this->sendImportResult($result);
    }

    /**
     * Verifies nonce and current user capability for management actions.
     */
    private function guard(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'atlas-backup-migration')], 403);
        }
    }

    /**
     * Sends a normalized JSON response for smart import operations.
     *
     * @param array|\WP_Error $result Import result or error.
     */
    private function sendImportResult($result): void
    {
        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            $status = is_array($error_data) ? absint($error_data['status'] ?? 400) : 400;
            wp_send_json_error(['message' => $result->get_error_message()], $status);
        }

        wp_send_json_success($result);
    }
}
