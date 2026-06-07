# Atlas Backup Migration

Atlas Backup Migration is a production-oriented WordPress backup, migration, and granular import/export plugin. It creates full migration packages with a standalone installer, exports focused WooCommerce/Elementor/theme payloads, and supports secure site-to-site sync for controlled production moves.

## Features

- **Full backups:** Packages WordPress files, chunked SQL dumps, compatibility manifests, and a generated `installer.php`.
- **Granular exports:** Builds focused ZIP packages for WooCommerce products, Elementor pages/templates, and themes with related options.
- **Smart importer:** Imports granular packages with resumable AJAX chunks stored in WordPress transients.
- **ID remapping:** Remaps attachments, product IDs, WooCommerce variation `post_parent`, taxonomy `term_id`, `term_taxonomy_id`, and parent/child term relationships.
- **Security hardening:** Protects backup/import directories with `.htaccess` and empty `index.php` files, validates ZIP entries before extraction, and avoids unsafe `extractTo` usage.
- **Cleanup tools:** Deletes temporary import files from the admin UI and includes installer-side cleanup for full migrations.
- **Compatibility support:** Detects WooCommerce, Dokan, and Elementor tables/files and includes restoration metadata.
- **Site-to-site sync:** Provides token-protected REST endpoints for product payloads and media chunk transfer.
- **Localization:** Ships Persian `fa_IR` translation files under `languages/`.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- PHP extensions: `zip`, `mysqli`, `json`
- Writable WordPress uploads directory
- Administrator access for backup, import, download, and cleanup actions

## Installation

1. Copy this repository to `wp-content/plugins/atlas-backup-migration`.
2. Activate **Atlas Backup Migration** from WordPress Admin → Plugins.
3. Open **Atlas Backup** from the WordPress admin menu.

## Usage

### Full Backup

1. Go to **Atlas Backup → Full Backup**.
2. Click **Start Full Backup**.
3. Keep the browser tab open while files and database rows are processed in chunks.
4. Download the package ZIP and `installer.php` after completion.

### Granular Export

1. Go to **Atlas Backup → Granular Export**.
2. Choose WooCommerce products, Elementor pages, or a theme package.
3. Click **Create Granular Package**.
4. Download the generated ZIP from the result area or Backup Manager.

### Smart Importer

1. Go to **Atlas Backup → Smart Importer**.
2. Upload a granular package ZIP.
3. The importer prepares a protected temporary session and processes media/items in resumable AJAX chunks.
4. If a request times out, resubmit/process the session to continue from the transient-saved index.
5. Click **Delete Temporary Import Files** after verifying the imported content.

### Site-to-Site Sync

1. Open **Settings & Sync**.
2. Generate a 4-hour token.
3. Send `X-ABM-Token-ID` and `X-ABM-Token` headers to the REST endpoints:
   - `POST /wp-json/atlas-backup-migration/v1/sync/validate`
   - `GET /wp-json/atlas-backup-migration/v1/sync/product/{id}`
   - `POST /wp-json/atlas-backup-migration/v1/sync/product`
   - `GET|POST /wp-json/atlas-backup-migration/v1/sync/media-chunk`

## Security Notes

- Backup files are stored in `wp-content/uploads/atlas-backup-migration/` with `.htaccess` and `index.php` guards.
- Downloads are served through capability-checked, nonce-protected admin endpoints.
- ZIP extraction validates every entry to block directory traversal and absolute paths.
- Temporary import packages remain in protected storage until admin cleanup runs.
- Never leave generated `installer.php` or migration archives on a public server after restoration.

## Development

```bash
php -l atlas-backup-migration.php
find includes templates -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Project Structure

```text
atlas-backup-migration/
├── atlas-backup-migration.php
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── includes/
│   ├── Admin/
│   ├── Ajax/
│   ├── Backup/
│   ├── Compatibility/
│   ├── Export/
│   ├── Import/
│   └── Sync/
├── languages/
└── templates/
```

## License

No public license is included in this repository. Add a project-approved license before public distribution.
