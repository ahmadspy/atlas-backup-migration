<?php
/**
 * Time-limited site-to-site sync token service.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Sync;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

final class AuthTokenService
{
    public const TRANSIENT_PREFIX = 'abm_sync_token_';
    public const TOKEN_TTL = 14400;

    public function issue(string $label = ''): array
    {
        try {
            $token = bin2hex(random_bytes(32));
        } catch (\Exception $exception) {
            unset($exception);
            $token = wp_generate_password(64, false, false);
        }
        $token_id = wp_generate_uuid4();
        $issued_at = time();
        $expires_at = $issued_at + self::TOKEN_TTL;

        set_transient(
            self::TRANSIENT_PREFIX . $token_id,
            [
                'hash' => wp_hash_password($token),
                'label' => sanitize_text_field($label),
                'issued_at' => $issued_at,
                'expires_at' => $expires_at,
                'site_url' => site_url(),
            ],
            self::TOKEN_TTL
        );

        return [
            'token_id' => $token_id,
            'token' => $token,
            'expires_at' => $expires_at,
            'expires_in' => self::TOKEN_TTL,
        ];
    }

    public function validate(string $token_id, string $token)
    {
        $token_id = sanitize_key($token_id);
        $token = trim($token);

        if ('' === $token_id || '' === $token) {
            return new WP_Error('abm_missing_token', __('Missing sync token.', 'atlas-backup-migration'), ['status' => 401]);
        }

        $record = get_transient(self::TRANSIENT_PREFIX . $token_id);

        if (! is_array($record) || empty($record['hash']) || time() > absint($record['expires_at'] ?? 0)) {
            return new WP_Error('abm_expired_token', __('Sync token is invalid or expired.', 'atlas-backup-migration'), ['status' => 401]);
        }

        if (! wp_check_password($token, (string) $record['hash'])) {
            return new WP_Error('abm_bad_token', __('Sync token authentication failed.', 'atlas-backup-migration'), ['status' => 403]);
        }

        return $record;
    }

    public function revoke(string $token_id): void
    {
        delete_transient(self::TRANSIENT_PREFIX . sanitize_key($token_id));
    }
}
