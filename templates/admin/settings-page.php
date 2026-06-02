<?php
/**
 * Admin settings page template.
 *
 * @package AtlasBackupMigration
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap abm-wrap" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
    <div class="abm-shell">
        <section class="abm-hero">
            <div>
                <span class="abm-eyebrow"><?php esc_html_e('Backup & Migration Suite', 'atlas-backup-migration'); ?></span>
                <h1><?php esc_html_e('Atlas Backup Migration', 'atlas-backup-migration'); ?></h1>
                <p><?php esc_html_e('A modular backup workspace for full packages, granular exports, smart imports, and secure site-to-site sync.', 'atlas-backup-migration'); ?></p>
            </div>
            <div class="abm-hero-card">
                <span><?php esc_html_e('Plugin Status', 'atlas-backup-migration'); ?></span>
                <strong><?php esc_html_e('Ready', 'atlas-backup-migration'); ?></strong>
            </div>
        </section>

        <nav class="abm-tabs" aria-label="<?php esc_attr_e('Atlas Backup sections', 'atlas-backup-migration'); ?>">
            <button type="button" class="abm-tab is-active" data-tab="manager"><?php esc_html_e('Backup Manager', 'atlas-backup-migration'); ?></button>
            <button type="button" class="abm-tab" data-tab="full"><?php esc_html_e('Full Backup', 'atlas-backup-migration'); ?></button>
            <button type="button" class="abm-tab" data-tab="granular"><?php esc_html_e('Granular Export', 'atlas-backup-migration'); ?></button>
            <button type="button" class="abm-tab" data-tab="importer"><?php esc_html_e('Smart Importer', 'atlas-backup-migration'); ?></button>
            <button type="button" class="abm-tab" data-tab="settings"><?php esc_html_e('Settings & Sync', 'atlas-backup-migration'); ?></button>
        </nav>

        <section class="abm-tab-panel is-active" data-panel="manager">
            <div class="abm-card">
                <div class="abm-card-header">
                    <div>
                        <h2><?php esc_html_e('Backup Manager', 'atlas-backup-migration'); ?></h2>
                        <p><?php esc_html_e('View complete and granular backup packages, download them securely, or remove old packages.', 'atlas-backup-migration'); ?></p>
                    </div>
                    <button type="button" class="button abm-button abm-refresh-backups"><?php esc_html_e('Refresh List', 'atlas-backup-migration'); ?></button>
                </div>

                <div class="abm-manager-status"></div>
                <div class="abm-table-wrap">
                    <table class="widefat striped abm-backup-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Package', 'atlas-backup-migration'); ?></th>
                                <th><?php esc_html_e('Type', 'atlas-backup-migration'); ?></th>
                                <th><?php esc_html_e('Size', 'atlas-backup-migration'); ?></th>
                                <th><?php esc_html_e('Created', 'atlas-backup-migration'); ?></th>
                                <th><?php esc_html_e('Actions', 'atlas-backup-migration'); ?></th>
                            </tr>
                        </thead>
                        <tbody class="abm-backup-list">
                            <tr><td colspan="5"><?php esc_html_e('Loading backup packages...', 'atlas-backup-migration'); ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="abm-tab-panel" data-panel="full" hidden>
            <div class="abm-card">
                <div class="abm-backup-panel">
                    <div>
                        <span class="abm-eyebrow abm-eyebrow-light"><?php esc_html_e('Batch Backup Engine', 'atlas-backup-migration'); ?></span>
                        <h2><?php esc_html_e('Create Full Migration Package', 'atlas-backup-migration'); ?></h2>
                        <p><?php esc_html_e('Build a ZIP package from all WordPress files, export the database in chunks, and generate a standalone installer.php.', 'atlas-backup-migration'); ?></p>
                    </div>

                    <button type="button" class="button abm-button abm-start-backup">
                        <?php esc_html_e('Start Full Backup', 'atlas-backup-migration'); ?>
                    </button>
                </div>

                <div class="abm-progress-box" hidden>
                    <div class="abm-progress-header">
                        <strong class="abm-status-text"><?php esc_html_e('Waiting...', 'atlas-backup-migration'); ?></strong>
                        <span class="abm-phase-pill"><?php esc_html_e('Idle', 'atlas-backup-migration'); ?></span>
                    </div>

                    <div class="abm-progress-row">
                        <span><?php esc_html_e('Files', 'atlas-backup-migration'); ?></span>
                        <div class="abm-progress"><span class="abm-file-bar"></span></div>
                        <strong class="abm-file-percent">0%</strong>
                    </div>

                    <div class="abm-progress-row">
                        <span><?php esc_html_e('Database', 'atlas-backup-migration'); ?></span>
                        <div class="abm-progress"><span class="abm-db-bar"></span></div>
                        <strong class="abm-db-percent">0%</strong>
                    </div>

                    <div class="abm-downloads" hidden>
                        <a class="button button-primary abm-package-link" href="#" target="_blank" rel="noopener">
                            <?php esc_html_e('Download Package ZIP', 'atlas-backup-migration'); ?>
                        </a>
                        <a class="button abm-installer-link" href="#" target="_blank" rel="noopener">
                            <?php esc_html_e('Download installer.php', 'atlas-backup-migration'); ?>
                        </a>
                    </div>

                    <ul class="abm-compatibility-list"></ul>
                </div>
            </div>
        </section>

        <section class="abm-tab-panel" data-panel="granular" hidden>
            <div class="abm-card">
                <div class="abm-card-header">
                    <div>
                        <h2><?php esc_html_e('Granular Export', 'atlas-backup-migration'); ?></h2>
                        <p><?php esc_html_e('Create a focused package with data.json plus required media or files for a selected module.', 'atlas-backup-migration'); ?></p>
                    </div>
                </div>

                <form class="abm-granular-form">
                    <label class="abm-field">
                        <span><?php esc_html_e('Package Label', 'atlas-backup-migration'); ?></span>
                        <input type="text" name="label" placeholder="<?php esc_attr_e('Example: Woo products for staging', 'atlas-backup-migration'); ?>">
                    </label>

                    <div class="abm-choice-grid">
                        <label class="abm-choice-card">
                            <input type="radio" name="export_type" value="woocommerce" checked>
                            <strong><?php esc_html_e('WooCommerce Products', 'atlas-backup-migration'); ?></strong>
                            <span><?php esc_html_e('Products, product meta, terms, featured images, and galleries.', 'atlas-backup-migration'); ?></span>
                        </label>
                        <label class="abm-choice-card">
                            <input type="radio" name="export_type" value="elementor">
                            <strong><?php esc_html_e('Elementor Pages', 'atlas-backup-migration'); ?></strong>
                            <span><?php esc_html_e('Pages/templates with Elementor metadata for smart restore.', 'atlas-backup-migration'); ?></span>
                        </label>
                        <label class="abm-choice-card">
                            <input type="radio" name="export_type" value="theme">
                            <strong><?php esc_html_e('Theme + Options', 'atlas-backup-migration'); ?></strong>
                            <span><?php esc_html_e('A selected theme directory plus theme-related wp_options values.', 'atlas-backup-migration'); ?></span>
                        </label>
                    </div>

                    <label class="abm-field abm-theme-picker">
                        <span><?php esc_html_e('Theme', 'atlas-backup-migration'); ?></span>
                        <select name="theme_slug">
                            <?php foreach (wp_get_themes() as $slug => $theme) : ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected(get_stylesheet(), $slug); ?>>
                                    <?php echo esc_html($theme->get('Name')); ?><?php echo get_stylesheet() === $slug ? esc_html__(' (active)', 'atlas-backup-migration') : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <div class="abm-actions">
                        <button type="submit" class="button abm-button abm-create-granular"><?php esc_html_e('Create Granular Package', 'atlas-backup-migration'); ?></button>
                    </div>
                </form>

                <div class="abm-granular-result" hidden></div>
            </div>
        </section>

        <section class="abm-tab-panel" data-panel="importer" hidden>
            <div class="abm-card">
                <div class="abm-card-header">
                    <div>
                        <h2><?php esc_html_e('Smart Importer', 'atlas-backup-migration'); ?></h2>
                        <p><?php esc_html_e('Upload a granular package from another site and import only the supported data into this site.', 'atlas-backup-migration'); ?></p>
                    </div>
                </div>

                <form class="abm-import-form" enctype="multipart/form-data">
                    <label class="abm-upload-drop">
                        <input type="file" name="package" accept=".zip,application/zip" required>
                        <strong><?php esc_html_e('Choose Granular ZIP Package', 'atlas-backup-migration'); ?></strong>
                        <span><?php esc_html_e('Supported now: WooCommerce products, Elementor pages, and theme packages generated by Atlas.', 'atlas-backup-migration'); ?></span>
                    </label>
                    <div class="abm-actions">
                        <button type="submit" class="button abm-button"><?php esc_html_e('Upload & Import', 'atlas-backup-migration'); ?></button>
                    </div>
                </form>

                <div class="abm-import-result" hidden></div>
                <div class="abm-import-progress" hidden>
                    <div class="abm-progress-row">
                        <span><?php esc_html_e('Import', 'atlas-backup-migration'); ?></span>
                        <div class="abm-progress"><span class="abm-import-bar"></span></div>
                        <strong class="abm-import-percent">0%</strong>
                    </div>
                    <small class="abm-import-status"></small>
                </div>
                <div class="abm-actions abm-cleanup-actions">
                    <button type="button" class="button abm-cleanup-import" hidden><?php esc_html_e('Delete Temporary Import Files', 'atlas-backup-migration'); ?></button>
                </div>
            </div>
        </section>

        <section class="abm-tab-panel" data-panel="settings" hidden>
            <div class="abm-grid">
                <main class="abm-card abm-card-main">
                    <div class="abm-card-header">
                        <div>
                            <h2><?php esc_html_e('General Settings', 'atlas-backup-migration'); ?></h2>
                            <p><?php esc_html_e('Configure the first layer of your backup and migration workflow.', 'atlas-backup-migration'); ?></p>
                        </div>
                    </div>

                    <form method="post" action="options.php" class="abm-form">
                        <?php settings_fields('abm_settings_group'); ?>

                        <label class="abm-field">
                            <span><?php esc_html_e('Backup Destination', 'atlas-backup-migration'); ?></span>
                            <select name="abm_settings[backup_location]">
                                <option value="local" <?php selected($settings['backup_location'], 'local'); ?>><?php esc_html_e('Local Storage', 'atlas-backup-migration'); ?></option>
                                <option value="cloud" <?php selected($settings['backup_location'], 'cloud'); ?>><?php esc_html_e('Cloud Storage', 'atlas-backup-migration'); ?></option>
                                <option value="both" <?php selected($settings['backup_location'], 'both'); ?>><?php esc_html_e('Local + Cloud', 'atlas-backup-migration'); ?></option>
                            </select>
                        </label>

                        <label class="abm-field">
                            <span><?php esc_html_e('Retention Days', 'atlas-backup-migration'); ?></span>
                            <input type="number" min="1" max="365" name="abm_settings[retention_days]" value="<?php echo esc_attr($settings['retention_days']); ?>">
                        </label>

                        <label class="abm-toggle">
                            <input type="checkbox" name="abm_settings[email_notifications]" value="1" <?php checked($settings['email_notifications']); ?>>
                            <span class="abm-toggle-ui"></span>
                            <span>
                                <strong><?php esc_html_e('Email Notifications', 'atlas-backup-migration'); ?></strong>
                                <small><?php esc_html_e('Send backup and migration reports to site administrators.', 'atlas-backup-migration'); ?></small>
                            </span>
                        </label>

                        <div class="abm-actions">
                            <?php submit_button(__('Save Settings', 'atlas-backup-migration'), 'primary abm-button', 'submit', false); ?>
                        </div>
                    </form>
                </main>

                <aside class="abm-sidebar">
                    <div class="abm-card">
                        <h2><?php esc_html_e('Site-to-Site Sync', 'atlas-backup-migration'); ?></h2>
                        <p><?php esc_html_e('Generate a temporary REST API token for direct secure transfer between WordPress sites.', 'atlas-backup-migration'); ?></p>

                        <label class="abm-field">
                            <span><?php esc_html_e('Connection Label', 'atlas-backup-migration'); ?></span>
                            <input type="text" class="abm-sync-label" placeholder="<?php esc_attr_e('Target site name', 'atlas-backup-migration'); ?>">
                        </label>

                        <button type="button" class="button abm-button abm-generate-sync-token"><?php esc_html_e('Generate 4-Hour Token', 'atlas-backup-migration'); ?></button>

                        <div class="abm-token-box" hidden>
                            <label><span><?php esc_html_e('Token ID', 'atlas-backup-migration'); ?></span><code class="abm-token-id"></code></label>
                            <label><span><?php esc_html_e('Token', 'atlas-backup-migration'); ?></span><code class="abm-token-value"></code></label>
                            <small class="abm-token-expiry"></small>
                        </div>

                        <ul class="abm-endpoint-list">
                            <li><code>/sync/validate</code></li>
                            <li><code>/sync/product/{id}</code></li>
                            <li><code>/sync/media-chunk/{id}</code></li>
                        </ul>
                    </div>

                    <div class="abm-card abm-stat-card">
                        <span><?php esc_html_e('Version', 'atlas-backup-migration'); ?></span>
                        <strong><?php echo esc_html(ABM_VERSION); ?></strong>
                    </div>
                </aside>
            </div>
        </section>
    </div>
</div>
