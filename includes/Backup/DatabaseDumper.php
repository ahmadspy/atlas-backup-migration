<?php
/**
 * Chunked database SQL dumper.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Backup;

use AtlasBackupMigration\Compatibility\CompatibilityModule;
use wpdb;

if (! defined('ABSPATH')) {
    exit;
}

final class DatabaseDumper
{
    private wpdb $wpdb;

    public function __construct(wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function prepare(BackupJob $job): array
    {
        $tables = $this->wpdb->get_col('SHOW TABLES');
        $tables = array_values(array_filter(array_map('strval', is_array($tables) ? $tables : []), [$this, 'isSafeIdentifier']));
        $manifest = is_array($job->state()['compatibility'] ?? null) ? $job->state()['compatibility'] : [];
        $tables = (new CompatibilityModule($this->wpdb))->tablePriority($tables, $manifest);

        $header = sprintf(
            "-- Atlas Backup Migration SQL Dump\n-- Created: %s UTC\n-- Source Site: %s\nSET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\nSET time_zone = \"+00:00\";\n\n",
            gmdate('Y-m-d H:i:s'),
            esc_sql((string) ($manifest['site_url'] ?? site_url()))
        );

        if (false === file_put_contents($job->sqlPath(), $header, LOCK_EX)) {
            return $job->update([
                'status' => 'failed',
                'errors' => array_merge($job->state()['errors'] ?? [], ['Unable to write database dump file.']),
            ]);
        }

        return $job->update([
            'tables' => $tables,
            'table_index' => 0,
            'current_table' => $tables[0] ?? '',
            'current_table_offset' => 0,
            'phase' => 'database',
            'status' => 'database_running',
        ]);
    }

    public function dumpRows(BackupJob $job, int $row_limit): array
    {
        $row_limit = max(1, $row_limit);
        $state = $job->state();
        $tables = $state['tables'] ?? [];
        $table_index = absint($state['table_index'] ?? 0);
        $offset = absint($state['current_table_offset'] ?? 0);

        if ($table_index >= count($tables)) {
            return $job->update([
                'phase' => 'installer',
                'status' => 'database_done',
            ]);
        }

        $table = (string) $tables[$table_index];

        if (0 === $offset) {
            $this->writeTableHeader($job->sqlPath(), $table);
        }

        $rows = $this->wpdb->get_results(
            sprintf('SELECT * FROM `%s` LIMIT %d OFFSET %d', esc_sql($table), $row_limit, $offset),
            ARRAY_A
        );

        $rows = is_array($rows) ? $rows : [];

        if ([] !== $rows) {
            $this->writeInsertRows($job->sqlPath(), $table, $rows);
            $offset += count($rows);
        }

        if (count($rows) < $row_limit) {
            $table_index++;
            $offset = 0;
        }

        return $job->update([
            'table_index' => $table_index,
            'current_table' => $tables[$table_index] ?? '',
            'current_table_offset' => $offset,
            'phase' => $table_index >= count($tables) ? 'installer' : 'database',
            'status' => $table_index >= count($tables) ? 'database_done' : 'database_running',
        ]);
    }

    public function isPrepared(BackupJob $job): bool
    {
        $state = $job->state();

        return ! empty($state['tables']) || file_exists($job->sqlPath());
    }

    public function isSafeIdentifier(string $identifier): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_]+$/', $identifier);
    }

    private function writeTableHeader(string $path, string $table): void
    {
        $create = $this->wpdb->get_row(sprintf('SHOW CREATE TABLE `%s`', esc_sql($table)), ARRAY_N);
        $create_sql = is_array($create) && isset($create[1]) ? (string) $create[1] : '';

        $sql = "\nDROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= $create_sql ? $create_sql . ";\n\n" : '';

        file_put_contents($path, $sql, FILE_APPEND | LOCK_EX);
    }

    private function writeInsertRows(string $path, string $table, array $rows): void
    {
        foreach ($rows as $row) {
            $columns = array_map(static function ($column): string {
                return '`' . str_replace('`', '``', (string) $column) . '`';
            }, array_keys($row));
            $values = array_map([$this, 'sqlValue'], array_values($row));

            $sql = sprintf(
                "INSERT INTO `%s` (%s) VALUES (%s);\n",
                $table,
                implode(', ', $columns),
                implode(', ', $values)
            );

            file_put_contents($path, $sql, FILE_APPEND | LOCK_EX);
        }
    }

    private function sqlValue($value): string
    {
        if (null === $value) {
            return 'NULL';
        }

        return "'" . esc_sql((string) $value) . "'";
    }
}
