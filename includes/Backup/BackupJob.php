<?php
/**
 * Backup job state manager.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Backup;

if (! defined('ABSPATH')) {
    exit;
}

final class BackupJob
{
    private string $job_id;
    private string $base_dir;
    private string $job_dir;
    private string $manifest_path;

    /**
     * Creates a job state object for a known job ID.
     *
     * @param string $job_id Backup job ID.
     */
    public function __construct(string $job_id)
    {
        $this->job_id = sanitize_key($job_id);
        $upload_dir = wp_upload_dir(null, false);
        $this->base_dir = trailingslashit($upload_dir['basedir']) . 'atlas-backup-migration';
        $this->job_dir = trailingslashit($this->base_dir) . $this->job_id;
        $this->manifest_path = trailingslashit($this->job_dir) . 'manifest.json';
    }

    /**
     * Creates and persists a new backup job.
     *
     * @return self
     */
    public static function create(): self
    {
        $job = new self(gmdate('YmdHis') . '-' . wp_generate_password(8, false, false));
        $job->protectStorage();
        wp_mkdir_p($job->job_dir);
        $job->protectDirectory($job->job_dir);

        $job->save([
            'job_id' => $job->job_id,
            'created_at' => time(),
            'status' => 'created',
            'phase' => 'scan',
            'root_path' => ABSPATH,
            'file_index' => 0,
            'files' => [],
            'table_index' => 0,
            'tables' => [],
            'current_table' => '',
            'current_table_offset' => 0,
            'package_name' => 'atlas-package-' . $job->job_id . '.zip',
            'sql_name' => 'database.sql',
            'installer_name' => 'installer.php',
            'errors' => [],
        ]);

        return $job;
    }

    /**
     * Returns the backup job ID.
     *
     * @return string
     */
    public function id(): string
    {
        return $this->job_id;
    }

    /**
     * Returns the backup job directory.
     *
     * @return string
     */
    public function dir(): string
    {
        return trailingslashit($this->job_dir);
    }

    /**
     * Returns the full package ZIP path.
     *
     * @return string
     */
    public function packagePath(): string
    {
        $state = $this->state();

        return $this->dir() . ($state['package_name'] ?? 'atlas-package-' . $this->job_id . '.zip');
    }

    /**
     * Returns the generated SQL dump path.
     *
     * @return string
     */
    public function sqlPath(): string
    {
        $state = $this->state();

        return $this->dir() . ($state['sql_name'] ?? 'database.sql');
    }

    /**
     * Returns the generated standalone installer path.
     *
     * @return string
     */
    public function installerPath(): string
    {
        $state = $this->state();

        return $this->dir() . ($state['installer_name'] ?? 'installer.php');
    }

    /**
     * Returns the compatibility manifest path.
     *
     * @return string
     */
    public function compatibilityManifestPath(): string
    {
        return $this->dir() . 'compatibility-manifest.json';
    }

    /**
     * Reads the persisted job state.
     *
     * @return array
     */
    public function state(): array
    {
        if (! file_exists($this->manifest_path)) {
            return [];
        }

        $state = json_decode((string) file_get_contents($this->manifest_path), true);

        return is_array($state) ? $state : [];
    }

    /**
     * Persists the job state to manifest.json.
     *
     * @param array $state Job state.
     */
    public function save(array $state): void
    {
        wp_mkdir_p($this->job_dir);
        $this->protectStorage();
        $this->protectDirectory($this->job_dir);

        $encoded = wp_json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (false === $encoded || false === file_put_contents($this->manifest_path, $encoded, LOCK_EX)) {
            wp_die(
                esc_html__('Unable to write backup job state.', 'atlas-backup-migration'),
                esc_html__('Backup Error', 'atlas-backup-migration'),
                ['response' => 500]
            );
        }
    }

    /**
     * Merges and persists state changes.
     *
     * @param array $changes State changes.
     * @return array
     */
    public function update(array $changes): array
    {
        $state = array_merge($this->state(), $changes);
        $this->save($state);

        return $state;
    }

    /**
     * Builds nonce-protected admin download URLs.
     *
     * @return array
     */
    public function downloadUrls(): array
    {
        $nonce = wp_create_nonce('abm_download_backup_' . $this->job_id);

        return [
            'package' => add_query_arg(
                [
                    'action' => 'abm_download_backup',
                    'job_id' => $this->job_id,
                    'file' => 'package',
                    '_wpnonce' => $nonce,
                ],
                admin_url('admin-post.php')
            ),
            'installer' => add_query_arg(
                [
                    'action' => 'abm_download_backup',
                    'job_id' => $this->job_id,
                    'file' => 'installer',
                    '_wpnonce' => $nonce,
                ],
                admin_url('admin-post.php')
            ),
        ];
    }

    /**
     * Protects the base backup storage directory from direct web access.
     */
    private function protectStorage(): void
    {
        wp_mkdir_p($this->base_dir);
        $this->protectDirectory($this->base_dir);

        $web_config = trailingslashit($this->base_dir) . 'web.config';

        if (! file_exists($web_config)) {
            file_put_contents(
                $web_config,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
                LOCK_EX
            );
        }
    }

    /**
     * Adds basic Apache/PHP directory guards to a directory.
     *
     * @param string $directory Directory to protect.
     */
    private function protectDirectory(string $directory): void
    {
        wp_mkdir_p($directory);

        $index = trailingslashit($directory) . 'index.php';
        $htaccess = trailingslashit($directory) . '.htaccess';

        if (! file_exists($index)) {
            file_put_contents($index, '', LOCK_EX);
        }

        if (! file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n", LOCK_EX);
        }
    }
}
