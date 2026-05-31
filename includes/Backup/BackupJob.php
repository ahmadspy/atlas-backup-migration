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

    public function __construct(string $job_id)
    {
        $this->job_id = sanitize_key($job_id);
        $upload_dir = wp_upload_dir(null, false);
        $this->base_dir = trailingslashit($upload_dir['basedir']) . 'atlas-backup-migration';
        $this->job_dir = trailingslashit($this->base_dir) . $this->job_id;
        $this->manifest_path = trailingslashit($this->job_dir) . 'manifest.json';
    }

    public static function create(): self
    {
        $job = new self(gmdate('YmdHis') . '-' . wp_generate_password(8, false, false));
        $job->protectStorage();
        wp_mkdir_p($job->job_dir);

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

    public function id(): string
    {
        return $this->job_id;
    }

    public function dir(): string
    {
        return trailingslashit($this->job_dir);
    }

    public function packagePath(): string
    {
        $state = $this->state();

        return $this->dir() . ($state['package_name'] ?? 'atlas-package-' . $this->job_id . '.zip');
    }

    public function sqlPath(): string
    {
        $state = $this->state();

        return $this->dir() . ($state['sql_name'] ?? 'database.sql');
    }

    public function installerPath(): string
    {
        $state = $this->state();

        return $this->dir() . ($state['installer_name'] ?? 'installer.php');
    }

    public function compatibilityManifestPath(): string
    {
        return $this->dir() . 'compatibility-manifest.json';
    }

    public function state(): array
    {
        if (! file_exists($this->manifest_path)) {
            return [];
        }

        $state = json_decode((string) file_get_contents($this->manifest_path), true);

        return is_array($state) ? $state : [];
    }

    public function save(array $state): void
    {
        wp_mkdir_p($this->job_dir);

        $encoded = wp_json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (false === $encoded || false === file_put_contents($this->manifest_path, $encoded, LOCK_EX)) {
            wp_die(
                esc_html__('Unable to write backup job state.', 'atlas-backup-migration'),
                esc_html__('Backup Error', 'atlas-backup-migration'),
                ['response' => 500]
            );
        }
    }

    public function update(array $changes): array
    {
        $state = array_merge($this->state(), $changes);
        $this->save($state);

        return $state;
    }

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

    private function protectStorage(): void
    {
        wp_mkdir_p($this->base_dir);

        $index = trailingslashit($this->base_dir) . 'index.php';
        $htaccess = trailingslashit($this->base_dir) . '.htaccess';
        $web_config = trailingslashit($this->base_dir) . 'web.config';

        if (! file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n", LOCK_EX);
        }

        if (! file_exists($htaccess)) {
            file_put_contents($htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n", LOCK_EX);
        }

        if (! file_exists($web_config)) {
            file_put_contents(
                $web_config,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
                LOCK_EX
            );
        }
    }
}
