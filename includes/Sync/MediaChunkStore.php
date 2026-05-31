<?php
/**
 * Stores inbound media chunks safely.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Sync;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

final class MediaChunkStore
{
    private const CHUNK_DIR = 'abm-sync-chunks';
    private const MAX_CHUNK_BYTES = 786432;

    public function append(array $payload)
    {
        $transfer_id = sanitize_key($payload['transfer_id'] ?? '');
        $filename = sanitize_file_name($payload['filename'] ?? '');
        $chunk_index = absint($payload['chunk_index'] ?? 0);
        $total_chunks = max(1, absint($payload['total_chunks'] ?? 1));
        $sha256 = sanitize_text_field($payload['sha256'] ?? '');
        $source_attachment_id = absint($payload['source_attachment_id'] ?? 0);
        $encoded = (string) ($payload['data'] ?? '');

        if ('' === $transfer_id || '' === $filename || '' === $encoded) {
            return new WP_Error('abm_bad_media_chunk', __('Invalid media chunk payload.', 'atlas-backup-migration'), ['status' => 400]);
        }

        if ($chunk_index >= $total_chunks) {
            return new WP_Error('abm_bad_media_chunk_index', __('Media chunk index is out of range.', 'atlas-backup-migration'), ['status' => 400]);
        }

        $bytes = base64_decode($encoded, true);

        if (false === $bytes) {
            return new WP_Error('abm_bad_media_chunk_data', __('Media chunk is not valid base64.', 'atlas-backup-migration'), ['status' => 400]);
        }

        if (strlen($bytes) > self::MAX_CHUNK_BYTES) {
            return new WP_Error('abm_media_chunk_too_large', __('Media chunk is too large.', 'atlas-backup-migration'), ['status' => 413]);
        }

        $upload_dir = wp_upload_dir(null, false);
        $chunk_dir = trailingslashit($upload_dir['basedir']) . self::CHUNK_DIR . '/' . $transfer_id;
        wp_mkdir_p($chunk_dir);
        $this->protectDirectory(trailingslashit($upload_dir['basedir']) . self::CHUNK_DIR);

        $part_path = trailingslashit($chunk_dir) . sprintf('%06d.part', $chunk_index);

        if (false === file_put_contents($part_path, $bytes, LOCK_EX)) {
            return new WP_Error('abm_media_chunk_write_failed', __('Unable to write media chunk.', 'atlas-backup-migration'), ['status' => 500]);
        }

        if (! $this->allChunksReceived($chunk_dir, $total_chunks)) {
            return [
                'complete' => false,
                'received' => $this->receivedChunkCount($chunk_dir),
                'total' => $total_chunks,
            ];
        }

        $assembled = trailingslashit($chunk_dir) . $filename;
        $out = fopen($assembled, 'wb');

        if (false === $out) {
            return new WP_Error('abm_media_assemble_failed', __('Unable to assemble media file.', 'atlas-backup-migration'), ['status' => 500]);
        }

        for ($index = 0; $index < $total_chunks; $index++) {
            $part = trailingslashit($chunk_dir) . sprintf('%06d.part', $index);

            if (! is_readable($part)) {
                fclose($out);
                return new WP_Error('abm_missing_media_chunk', __('A media chunk is missing.', 'atlas-backup-migration'), ['status' => 409]);
            }

            $part_bytes = file_get_contents($part);

            if (false === $part_bytes || false === fwrite($out, $part_bytes)) {
                fclose($out);
                return new WP_Error('abm_media_assemble_failed', __('Unable to write assembled media file.', 'atlas-backup-migration'), ['status' => 500]);
            }
        }

        fclose($out);

        if ('' !== $sha256 && hash_file('sha256', $assembled) !== $sha256) {
            return new WP_Error('abm_media_checksum_failed', __('Media checksum verification failed.', 'atlas-backup-migration'), ['status' => 422]);
        }

        $attachment_id = $this->insertAttachment($assembled, $filename, sanitize_text_field($payload['mime_type'] ?? ''), $source_attachment_id);
        $this->cleanup($chunk_dir);

        return [
            'complete' => true,
            'attachment_id' => $attachment_id,
            'filename' => $filename,
        ];
    }

    private function insertAttachment(string $path, string $filename, string $mime_type, int $source_attachment_id): int
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $contents = file_get_contents($path);

        if (false === $contents) {
            return 0;
        }

        $upload = wp_upload_bits($filename, null, $contents);

        if (! empty($upload['error'])) {
            return 0;
        }

        $attachment_id = wp_insert_attachment([
            'post_title' => sanitize_text_field(pathinfo($filename, PATHINFO_FILENAME)),
            'post_mime_type' => $mime_type ?: (wp_check_filetype($filename)['type'] ?: 'application/octet-stream'),
            'post_status' => 'inherit',
        ], $upload['file']);

        if (! is_wp_error($attachment_id)) {
            $metadata = wp_generate_attachment_metadata((int) $attachment_id, $upload['file']);
            wp_update_attachment_metadata((int) $attachment_id, $metadata);
            update_post_meta((int) $attachment_id, '_abm_source_attachment_id', $source_attachment_id);

            return (int) $attachment_id;
        }

        return 0;
    }

    private function cleanup(string $directory): void
    {
        foreach (glob(trailingslashit($directory) . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    private function allChunksReceived(string $directory, int $total_chunks): bool
    {
        for ($index = 0; $index < $total_chunks; $index++) {
            if (! is_readable(trailingslashit($directory) . sprintf('%06d.part', $index))) {
                return false;
            }
        }

        return true;
    }

    private function receivedChunkCount(string $directory): int
    {
        $chunks = glob(trailingslashit($directory) . '*.part');

        return is_array($chunks) ? count($chunks) : 0;
    }

    private function protectDirectory(string $directory): void
    {
        wp_mkdir_p($directory);

        $index = trailingslashit($directory) . 'index.php';
        $htaccess = trailingslashit($directory) . '.htaccess';

        if (! file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n", LOCK_EX);
        }

        if (! file_exists($htaccess)) {
            file_put_contents($htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n", LOCK_EX);
        }
    }
}
