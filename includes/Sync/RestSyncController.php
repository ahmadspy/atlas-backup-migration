<?php
/**
 * REST API controller for secure site-to-site sync.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Sync;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
    exit;
}

final class RestSyncController
{
    private const NAMESPACE = 'atlas-backup-migration/v1';
    private AuthTokenService $tokens;

    public function __construct()
    {
        $this->tokens = new AuthTokenService();
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('wp_ajax_abm_generate_sync_token', [$this, 'ajaxGenerateToken']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/sync/validate', [
            'methods' => 'POST',
            'callback' => [$this, 'validateToken'],
            'permission_callback' => [$this, 'permissionFromRequest'],
        ]);

        register_rest_route(self::NAMESPACE, '/sync/product/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'exportProduct'],
            'permission_callback' => [$this, 'permissionFromRequest'],
            'args' => [
                'id' => ['sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/sync/product', [
            'methods' => 'POST',
            'callback' => [$this, 'importProduct'],
            'permission_callback' => [$this, 'permissionFromRequest'],
        ]);

        register_rest_route(self::NAMESPACE, '/sync/media-chunk/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'downloadMediaChunk'],
            'permission_callback' => [$this, 'permissionFromRequest'],
            'args' => [
                'id' => ['sanitize_callback' => 'absint'],
                'offset' => ['sanitize_callback' => 'absint'],
                'length' => ['sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/sync/media-chunk', [
            'methods' => 'POST',
            'callback' => [$this, 'receiveMediaChunk'],
            'permission_callback' => [$this, 'permissionFromRequest'],
        ]);
    }

    public function ajaxGenerateToken(): void
    {
        check_ajax_referer('abm_sync_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'atlas-backup-migration')], 403);
        }

        $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';
        wp_send_json_success($this->tokens->issue($label));
    }

    public function permissionFromRequest(WP_REST_Request $request)
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $token_id = $request->get_header('x-abm-token-id');
        $token = $request->get_header('x-abm-token');
        $validated = $this->tokens->validate((string) $token_id, (string) $token);

        if (is_wp_error($validated)) {
            return $validated;
        }

        return true;
    }

    public function validateToken(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'valid' => true,
            'site_url' => site_url(),
            'rest_url' => rest_url(self::NAMESPACE),
            'expires_checked_at' => time(),
        ]);
    }

    public function exportProduct(WP_REST_Request $request)
    {
        $payload = (new ProductPayloadBuilder())->build(absint($request['id']));

        if ([] === $payload) {
            return new WP_Error('abm_product_not_found', __('Product was not found.', 'atlas-backup-migration'), ['status' => 404]);
        }

        return new WP_REST_Response($payload);
    }

    public function importProduct(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        $result = (new ProductImporter())->import(is_array($payload) ? $payload : []);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 201);
    }

    public function downloadMediaChunk(WP_REST_Request $request)
    {
        $attachment_id = absint($request['id']);
        $offset = absint($request->get_param('offset'));
        $length = max(1, min(1024 * 512, absint($request->get_param('length') ?: 262144)));
        $path = get_attached_file($attachment_id);

        if (! is_string($path) || ! is_readable($path)) {
            return new WP_Error('abm_media_not_found', __('Media file was not found.', 'atlas-backup-migration'), ['status' => 404]);
        }

        $size = filesize($path);
        $size = false === $size ? 0 : (int) $size;

        if ($offset > $size) {
            return new WP_Error('abm_media_offset_out_of_range', __('Media chunk offset is out of range.', 'atlas-backup-migration'), ['status' => 416]);
        }

        $handle = fopen($path, 'rb');

        if (false === $handle) {
            return new WP_Error('abm_media_open_failed', __('Media file could not be opened.', 'atlas-backup-migration'), ['status' => 500]);
        }

        if (0 !== fseek($handle, $offset)) {
            fclose($handle);
            return new WP_Error('abm_media_seek_failed', __('Media file could not be read from the requested offset.', 'atlas-backup-migration'), ['status' => 500]);
        }

        $bytes = fread($handle, $length);
        fclose($handle);

        if (false === $bytes) {
            return new WP_Error('abm_media_read_failed', __('Media chunk could not be read.', 'atlas-backup-migration'), ['status' => 500]);
        }

        $next_offset = min($size, $offset + strlen((string) $bytes));

        return new WP_REST_Response([
            'attachment_id' => $attachment_id,
            'filename' => basename($path),
            'mime_type' => get_post_mime_type($attachment_id),
            'offset' => $offset,
            'next_offset' => $next_offset,
            'size' => $size,
            'complete' => $next_offset >= $size,
            'sha256' => hash_file('sha256', $path) ?: '',
            'data' => base64_encode((string) $bytes),
        ]);
    }

    public function receiveMediaChunk(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        $result = (new MediaChunkStore())->append(is_array($payload) ? $payload : []);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, ! empty($result['complete']) ? 201 : 202);
    }
}
