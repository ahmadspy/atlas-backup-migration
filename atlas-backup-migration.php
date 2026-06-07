<?php
/**
 * Plugin Name: Atlas Backup Migration
 * Plugin URI:  https://catus.ir/atlas-backup-migration
 * Description: Production-oriented WordPress backup, migration, granular import/export, and site-to-site sync plugin.
 * Version:     1.0.0
 * Author:      ahmadspy
 * Author URI:  https://catus.ir
 * Text Domain: atlas-backup-migration
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package AtlasBackupMigration
 */

if (! defined('ABSPATH')) {
    exit;
}

define('ABM_VERSION', '1.0.0');
define('ABM_FILE', __FILE__);
define('ABM_PATH', plugin_dir_path(__FILE__));
define('ABM_URL', plugin_dir_url(__FILE__));
define('ABM_BASENAME', plugin_basename(__FILE__));

require_once ABM_PATH . 'includes/Plugin.php';

register_activation_hook(__FILE__, ['AtlasBackupMigration\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['AtlasBackupMigration\\Plugin', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    AtlasBackupMigration\Plugin::instance()->boot();
});
