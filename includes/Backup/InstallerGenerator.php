<?php
/**
 * Standalone installer generator.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Backup;

if (! defined('ABSPATH')) {
    exit;
}

final class InstallerGenerator
{
    public function generate(BackupJob $job): array
    {
        $template = file_get_contents(ABM_PATH . 'templates/installer/installer.php');

        if (false === $template) {
            return $job->update([
                'status' => 'failed',
                'errors' => array_merge($job->state()['errors'] ?? [], ['Installer template could not be read.']),
            ]);
        }

        $state = $job->state();
        $replacements = [
            '{{PACKAGE_NAME}}' => addslashes((string) ($state['package_name'] ?? basename($job->packagePath()))),
            '{{SQL_NAME}}' => addslashes((string) ($state['sql_name'] ?? 'database.sql')),
            '{{CREATED_AT}}' => addslashes(gmdate('c')),
        ];

        if (false === file_put_contents($job->installerPath(), strtr((string) $template, $replacements), LOCK_EX)) {
            return $job->update([
                'status' => 'failed',
                'errors' => array_merge($state['errors'] ?? [], ['Installer file could not be written.']),
            ]);
        }

        return $job->update([
            'phase' => 'package',
            'status' => 'installer_done',
        ]);
    }
}
