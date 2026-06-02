<?php
/**
 * Backup AJAX controller.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Ajax;

use AtlasBackupMigration\Backup\BackupJob;
use AtlasBackupMigration\Backup\DatabaseDumper;
use AtlasBackupMigration\Backup\FileScanner;
use AtlasBackupMigration\Backup\InstallerGenerator;
use AtlasBackupMigration\Backup\ZipPackager;
use AtlasBackupMigration\Compatibility\CompatibilityModule;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handles chunked full backup AJAX actions and protected downloads.
 */
final class BackupAjaxController
{
    private const NONCE_ACTION = 'abm_backup_nonce';
    private const FILE_BATCH_SIZE = 80;
    private const DB_BATCH_SIZE = 120;

    /**
     * Registers AJAX and admin-post hooks.
     */
    public function register(): void
    {
        add_action('wp_ajax_abm_start_backup', [$this, 'start']);
        add_action('wp_ajax_abm_process_backup', [$this, 'process']);
        add_action('admin_post_abm_download_backup', [$this, 'download']);
    }

    /**
     * Returns the nonce action used for backup requests.
     *
     * @return string
     */
    public static function nonceAction(): string
    {
        return self::NONCE_ACTION;
    }

    /**
     * Creates a new backup job and returns its initial state.
     */
    public function start(): void
    {
        $this->guard();

        $job = BackupJob::create();

        wp_send_json_success($this->response($job->state(), $job));
    }

    /**
     * Processes the next backup phase chunk.
     */
    public function process(): void
    {
        global $wpdb;

        $this->guard();

        $job_id = isset($_POST['job_id']) ? sanitize_key(wp_unslash($_POST['job_id'])) : '';

        if ('' === $job_id) {
            wp_send_json_error(['message' => __('Missing backup job ID.', 'atlas-backup-migration')], 400);
        }

        $job = new BackupJob($job_id);
        $state = $job->state();

        if ([] === $state) {
            wp_send_json_error(['message' => __('Backup job was not found.', 'atlas-backup-migration')], 404);
        }

        $scanner = new FileScanner();
        $zipper = new ZipPackager();
        $dumper = new DatabaseDumper($wpdb);
        $installer = new InstallerGenerator();
        $compatibility = new CompatibilityModule($wpdb);

        if ('failed' === ($state['status'] ?? '')) {
            wp_send_json_error($this->response($state, $job), 500);
        }

        switch ($state['phase'] ?? 'scan') {
            case 'scan':
                $manifest = $compatibility->buildManifest();
                $manifest_json = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                if (false === $manifest_json || false === file_put_contents($job->compatibilityManifestPath(), $manifest_json, LOCK_EX)) {
                    $state = $job->update([
                        'status' => 'failed',
                        'errors' => array_merge($state['errors'] ?? [], [__('Unable to write compatibility manifest.', 'atlas-backup-migration')]),
                    ]);
                    break;
                }

                $files = $scanner->scan(
                    ABSPATH,
                    $job->dir(),
                    $compatibility->productAttachmentFiles($manifest),
                    $compatibility->requiredFilePrefixes($manifest)
                );
                $state = $job->update([
                    'files' => $files,
                    'total_files' => count($files),
                    'compatibility' => $manifest,
                    'phase' => 'files',
                    'status' => 'running',
                ]);
                break;

            case 'files':
                $state = $zipper->addFiles($job, self::FILE_BATCH_SIZE);
                break;

            case 'database':
                if (! $dumper->isPrepared($job)) {
                    $state = $dumper->prepare($job);
                } else {
                    $state = $dumper->dumpRows($job, self::DB_BATCH_SIZE);
                }
                break;

            case 'installer':
                $state = $installer->generate($job);
                break;

            case 'package':
                $state = $zipper->addGeneratedFiles($job);
                break;

            default:
                $state = $job->update([
                    'status' => 'failed',
                    'errors' => array_merge($state['errors'] ?? [], ['Unknown backup phase.']),
                ]);
                break;
        }

        if ('failed' === ($state['status'] ?? '')) {
            wp_send_json_error($this->response($state, $job), 500);
        }

        wp_send_json_success($this->response($state, $job));
    }

    /**
     * Streams a nonce-protected backup package or installer file.
     */
    public function download(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(
                esc_html__('Permission denied.', 'atlas-backup-migration'),
                esc_html__('Forbidden', 'atlas-backup-migration'),
                ['response' => 403]
            );
        }

        $job_id = isset($_GET['job_id']) ? sanitize_key(wp_unslash($_GET['job_id'])) : '';
        $file = isset($_GET['file']) ? sanitize_key(wp_unslash($_GET['file'])) : '';

        if ('' === $job_id || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'abm_download_backup_' . $job_id)) {
            wp_die(
                esc_html__('Invalid download request.', 'atlas-backup-migration'),
                esc_html__('Forbidden', 'atlas-backup-migration'),
                ['response' => 403]
            );
        }

        $job = new BackupJob($job_id);
        $state = $job->state();

        if ('completed' !== ($state['status'] ?? '')) {
            wp_die(
                esc_html__('Backup package is not ready.', 'atlas-backup-migration'),
                esc_html__('Not Found', 'atlas-backup-migration'),
                ['response' => 404]
            );
        }

        if (! in_array($file, ['installer', 'package'], true)) {
            wp_die(
                esc_html__('Invalid backup file type.', 'atlas-backup-migration'),
                esc_html__('Forbidden', 'atlas-backup-migration'),
                ['response' => 403]
            );
        }

        $path = 'installer' === $file ? $job->installerPath() : $job->packagePath();
        $real_path = wp_normalize_path(realpath($path) ?: '');
        $job_dir = wp_normalize_path(realpath($job->dir()) ?: '');

        if ('' === $real_path || '' === $job_dir || 0 !== strpos($real_path, trailingslashit($job_dir)) || ! is_readable($real_path) || ! is_file($real_path)) {
            wp_die(
                esc_html__('Requested backup file was not found.', 'atlas-backup-migration'),
                esc_html__('Not Found', 'atlas-backup-migration'),
                ['response' => 404]
            );
        }

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name(basename($real_path)) . '"');
        header('Content-Length: ' . filesize($real_path));
        readfile($real_path);
        exit;
    }

    /**
     * Verifies nonce and administrator capability for backup actions.
     */
    private function guard(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'atlas-backup-migration')], 403);
        }
    }

    /**
     * Builds a normalized backup progress response.
     *
     * @param array     $state Backup job state.
     * @param BackupJob $job Backup job.
     * @return array
     */
    private function response(array $state, BackupJob $job): array
    {
        $total_files = max(1, absint($state['total_files'] ?? count($state['files'] ?? [])));
        $file_index = absint($state['file_index'] ?? 0);
        $tables = is_array($state['tables'] ?? null) ? $state['tables'] : [];
        $table_total = max(1, count($tables));
        $table_index = absint($state['table_index'] ?? 0);
        $file_progress = min(100, (int) floor(($file_index / $total_files) * 100));
        $db_progress = min(100, (int) floor(($table_index / $table_total) * 100));

        return [
            'job_id' => $job->id(),
            'status' => $state['status'] ?? 'created',
            'phase' => $state['phase'] ?? 'scan',
            'message' => $this->message($state),
            'file_progress' => $file_progress,
            'db_progress' => $db_progress,
            'files_done' => $file_index,
            'files_total' => $total_files,
            'tables_done' => $table_index,
            'tables_total' => count($tables),
            'current_table' => $state['current_table'] ?? '',
            'downloads' => 'completed' === ($state['status'] ?? '') ? $job->downloadUrls() : [],
            'compatibility' => $this->compatibilitySummary($state),
            'errors' => $state['errors'] ?? [],
        ];
    }

    /**
     * Builds a compact compatibility summary for the UI.
     *
     * @param array $state Backup job state.
     * @return array
     */
    private function compatibilitySummary(array $state): array
    {
        $plugins = $state['compatibility']['plugins'] ?? [];
        $summary = [];

        foreach ($plugins as $slug => $plugin) {
            $summary[$slug] = [
                'active' => ! empty($plugin['active']),
                'tables' => count($plugin['tables'] ?? []),
                'required_file_prefixes' => count($plugin['required_file_prefixes'] ?? []),
                'product_media' => count($plugin['product_media'] ?? []),
            ];
        }

        return $summary;
    }

    /**
     * Returns the localized progress message for a backup phase.
     *
     * @param array $state Backup job state.
     * @return string
     */
    private function message(array $state): string
    {
        switch ($state['phase'] ?? 'scan') {
            case 'scan':
                return __('Scanning WordPress files...', 'atlas-backup-migration');
            case 'files':
                return __('Adding files to ZIP package...', 'atlas-backup-migration');
            case 'database':
                return __('Exporting database in safe batches...', 'atlas-backup-migration');
            case 'installer':
                return __('Generating standalone installer.php...', 'atlas-backup-migration');
            case 'package':
                return __('Finalizing migration package...', 'atlas-backup-migration');
            case 'done':
                return __('Backup package is ready.', 'atlas-backup-migration');
            default:
                return __('Processing backup...', 'atlas-backup-migration');
        }
    }
}
