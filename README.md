# Atlas Backup Migration

Boilerplate for an advanced WordPress backup and migration plugin using an OOP structure and a modern admin settings panel.

## Structure

```text
atlas-backup-migration/
├── atlas-backup-migration.php
├── includes/
│   ├── Plugin.php
│   ├── Ajax/
│   │   └── BackupAjaxController.php
│   ├── Backup/
│   │   ├── BackupJob.php
│   │   ├── DatabaseDumper.php
│   │   ├── FileScanner.php
│   │   ├── InstallerGenerator.php
│   │   └── ZipPackager.php
│   └── Admin/
│       └── SettingsPage.php
├── templates/
│   ├── installer/
│   │   └── installer.php
│   └── admin/
│       └── settings-page.php
├── assets/
│   ├── css/
│   │   └── admin.css
│   └── js/
│       └── admin.js
└── languages/
```

## Usage

Copy the `atlas-backup-migration` directory into `wp-content/plugins`, activate the plugin, then open **Atlas Backup** from the WordPress admin menu.

## Backup Flow

The admin panel starts a backup job with AJAX, scans WordPress files, adds files to a ZIP package in batches, exports SQL rows table-by-table, generates `installer.php`, and returns package download links.

## Phase 2 Admin Workspace

The admin screen is now separated into focused tabs:

- **Backup Manager** lists completed full and granular ZIP packages with size, secure download links, and delete actions.
- **Full Backup** keeps the original complete site + database package workflow.
- **Granular Export** creates focused packages with `data.json` plus required media or files for WooCommerce products, Elementor pages, or a selected theme and its options.
- **Smart Importer** uploads a granular package from another Atlas-powered site and imports supported data into the target site.

## Compatibility

The backup flow detects WooCommerce, Dokan, and Elementor. It prioritizes their custom tables, records product/gallery attachment files, includes Elementor generated CSS under uploads, stores a `compatibility-manifest.json`, and exposes installer actions for URL rewriting and Elementor CSS regeneration.

## Site-to-Site Sync

The sync layer exposes secured REST endpoints under `/wp-json/atlas-backup-migration/v1`. Generate a 4-hour token in the admin panel, then send `X-ABM-Token-ID` and `X-ABM-Token` headers to validate, export/import a product payload, and stream media through chunked base64 payloads.

Example product export request:

```bash
curl -H "X-ABM-Token-ID: TOKEN_ID" \
  -H "X-ABM-Token: TOKEN" \
  https://source.test/wp-json/atlas-backup-migration/v1/sync/product/123
```
# atlas-backup-migration
