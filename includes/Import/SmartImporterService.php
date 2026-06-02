<?php
/**
 * Imports focused migration packages.
 *
 * @package AtlasBackupMigration
 */

namespace AtlasBackupMigration\Import;

use AtlasBackupMigration\Backup\BackupRepository;
use AtlasBackupMigration\Sync\ProductImporter;
use RuntimeException;
use WP_Error;
use ZipArchive;

if (! defined('ABSPATH')) {
    exit;
}

final class SmartImporterService
{
    private const TRANSIENT_PREFIX = 'abm_import_session_';
    private const SESSION_TTL = 43200;
    private const MEDIA_BATCH_SIZE = 4;
    private const ITEM_BATCH_SIZE = 5;

    /**
     * Imports a package synchronously for legacy callers.
     *
     * @param array $file Uploaded package from $_FILES.
     * @return array|WP_Error
     */
    public function import(array $file)
    {
        $prepared = $this->prepare($file);

        if (is_wp_error($prepared)) {
            return $prepared;
        }

        $session_id = (string) $prepared['session_id'];
        $result = $prepared;

        do {
            $result = $this->process($session_id);

            if (is_wp_error($result)) {
                return $result;
            }
        } while (empty($result['done']));

        $this->cleanupSession($session_id);

        return $result;
    }

    /**
     * Stores an uploaded ZIP in protected storage and creates a resumable import session.
     *
     * @param array $file Uploaded package from $_FILES.
     * @return array|WP_Error
     */
    public function prepare(array $file)
    {
        if (! class_exists(ZipArchive::class)) {
            return new WP_Error('abm_zip_missing', __('PHP ZipArchive extension is not available.', 'atlas-backup-migration'), ['status' => 500]);
        }

        if (empty($file['tmp_name']) || ! is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('abm_bad_upload', __('No import package was uploaded.', 'atlas-backup-migration'), ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $session_id = wp_generate_uuid4();
        $work_dir = trailingslashit($this->importsBaseDir()) . 'import-' . $session_id;
        wp_mkdir_p($work_dir);
        $this->protectDirectory($this->importsBaseDir());
        $this->protectDirectory($work_dir);

        $upload = wp_handle_upload($file, ['test_form' => false, 'mimes' => ['zip' => 'application/zip']]);

        if (! empty($upload['error'])) {
            $this->cleanup($work_dir);
            return new WP_Error('abm_upload_failed', (string) $upload['error'], ['status' => 400]);
        }

        $uploaded_package = (string) $upload['file'];
        $package = trailingslashit($work_dir) . 'package.zip';

        if (! @rename($uploaded_package, $package)) {
            if (! @copy($uploaded_package, $package)) {
                $this->cleanup($work_dir);
                return new WP_Error('abm_upload_move_failed', __('Unable to move uploaded package into protected storage.', 'atlas-backup-migration'), ['status' => 500]);
            }

            @unlink($uploaded_package);
        }

        $zip = new ZipArchive();

        if (true !== $zip->open($package)) {
            $this->cleanup($work_dir);
            return new WP_Error('abm_import_open_failed', __('Unable to open import package.', 'atlas-backup-migration'), ['status' => 400]);
        }

        try {
            $this->safeExtract($zip, $work_dir);
        } catch (RuntimeException $exception) {
            $zip->close();
            $this->cleanup($work_dir);
            return new WP_Error('abm_import_extract_failed', $exception->getMessage(), ['status' => 400]);
        }

        $zip->close();
        $data_path = trailingslashit($work_dir) . 'data.json';
        $data = is_readable($data_path) ? json_decode((string) file_get_contents($data_path), true) : null;

        if (! is_array($data) || 'atlas-granular/v1' !== ($data['schema'] ?? '')) {
            $this->cleanup($work_dir);
            return new WP_Error('abm_bad_import_package', __('Import package data.json is invalid.', 'atlas-backup-migration'), ['status' => 400]);
        }

        $state = $this->defaultSessionState($session_id, $work_dir, $package, $data);
        $this->saveSession($session_id, $state);

        return $this->sessionResponse($state, [
            'message' => __('Import package prepared. Processing can resume safely if an AJAX request times out.', 'atlas-backup-migration'),
        ]);
    }

    /**
     * Processes the next resumable import chunk.
     *
     * @param string $session_id Import session UUID.
     * @return array|WP_Error
     */
    public function process(string $session_id)
    {
        $state = $this->getSession($session_id);

        if (is_wp_error($state)) {
            return $state;
        }

        $data = $this->sessionData($state);

        if (is_wp_error($data)) {
            return $data;
        }

        switch ($state['phase'] ?? 'media') {
            case 'media':
                return $this->processMediaChunk($state, $data);
            case 'items':
                return $this->processItemChunk($state, $data);
            case 'finalize':
                return $this->finalizeSession($state, $data);
            case 'done':
                return $this->sessionResponse($state);
            default:
                return new WP_Error('abm_bad_import_phase', __('Import session has an invalid phase.', 'atlas-backup-migration'), ['status' => 409]);
        }
    }

    /**
     * Deletes temporary files for a completed or abandoned import session.
     *
     * @param string $session_id Import session UUID.
     * @return array|WP_Error
     */
    public function cleanupSession(string $session_id)
    {
        $state = $this->getSession($session_id);

        if (is_wp_error($state)) {
            return $state;
        }

        $work_dir = (string) ($state['work_dir'] ?? '');

        if (! $this->isPathInside($work_dir, $this->importsBaseDir())) {
            return new WP_Error('abm_import_cleanup_unsafe', __('Import cleanup path is outside protected storage.', 'atlas-backup-migration'), ['status' => 403]);
        }

        $removed = $this->cleanup($work_dir);
        delete_transient(self::TRANSIENT_PREFIX . sanitize_key($session_id));

        return [
            'session_id' => sanitize_key($session_id),
            'removed' => $removed,
            'message' => __('Temporary import files were deleted.', 'atlas-backup-migration'),
        ];
    }

    /**
     * Dispatches a complete in-memory import by package type.
     *
     * @param array  $data Package manifest data.
     * @param string $work_dir Temporary extraction directory.
     * @return array|WP_Error
     */
    private function dispatch(array $data, string $work_dir)
    {
        switch (sanitize_key($data['type'] ?? '')) {
            case 'woocommerce':
                return $this->importWooCommerce($data, $work_dir);
            case 'elementor':
                return $this->importElementor($data, $work_dir);
            case 'theme':
                return $this->importTheme($data, $work_dir);
            default:
                return new WP_Error('abm_unknown_import_type', __('This granular package type is not supported.', 'atlas-backup-migration'), ['status' => 400]);
        }
    }

    /**
     * Imports all WooCommerce items from a fully loaded package.
     *
     * @param array  $data Package manifest data.
     * @param string $work_dir Temporary extraction directory.
     * @return array
     */
    private function importWooCommerce(array $data, string $work_dir): array
    {
        $media_map = $this->importPackagedMedia($work_dir, $this->collectMediaMetadata($data));
        $importer = new ProductImporter();
        $product_id_map = [];
        $term_id_map = [];
        $term_taxonomy_id_map = [];
        $imported = 0;
        $term_relationships_remapped = 0;

        foreach (($data['products'] ?? []) as $product) {
            if (! is_array($product)) {
                continue;
            }

            $result = $importer->import($product, $media_map, $product_id_map, $term_id_map, $term_taxonomy_id_map, false);

            if (! is_wp_error($result)) {
                $imported++;
                $product_id_map = $this->sanitizeIdMap((array) ($result['product_id_map'] ?? $product_id_map));
                $term_id_map = $this->sanitizeIdMap((array) ($result['term_id_map'] ?? $term_id_map));
                $term_taxonomy_id_map = $this->sanitizeIdMap((array) ($result['term_taxonomy_id_map'] ?? $term_taxonomy_id_map));
                $term_relationships_remapped += absint($result['term_relationships_remapped'] ?? 0);
            }
        }

        $parents_remapped = $importer->remapProductParents($product_id_map);
        $term_parents_remapped = $importer->remapTermParents($term_id_map, $term_taxonomy_id_map);

        return [
            'type' => 'woocommerce',
            'imported' => $imported,
            'media_remapped' => count($media_map),
            'products_remapped' => count($product_id_map),
            'term_ids_remapped' => count($term_id_map),
            'term_taxonomy_ids_remapped' => count($term_taxonomy_id_map),
            'term_relationships_remapped' => $term_relationships_remapped,
            'variation_parents_remapped' => $parents_remapped,
            'term_parents_remapped' => $term_parents_remapped,
            'message' => sprintf(__('Imported %1$d WooCommerce products with %2$d remapped media items, %3$d remapped taxonomy terms, and %4$d remapped variation parents.', 'atlas-backup-migration'), $imported, count($media_map), count($term_taxonomy_id_map), $parents_remapped),
        ];
    }

    /**
     * Sanitizes source-to-target ID maps before they are persisted.
     *
     * @param array $map Raw ID map.
     * @return array
     */
    private function sanitizeIdMap(array $map): array
    {
        $sanitized = [];

        foreach ($map as $source_id => $target_id) {
            $source_id = absint($source_id);
            $target_id = absint($target_id);

            if ($source_id && $target_id) {
                $sanitized[$source_id] = $target_id;
            }
        }

        return $sanitized;
    }

    /**
     * Imports Elementor pages/templates from a full package.
     *
     * @param array  $data Package manifest data.
     * @param string $work_dir Temporary extraction directory.
     * @return array
     */
    private function importElementor(array $data, string $work_dir): array
    {
        $media_map = $this->importPackagedMedia($work_dir, $this->collectMediaMetadata($data));
        $remapper = new IdRemapper();
        $imported = 0;

        foreach (($data['pages'] ?? []) as $item) {
            if ($this->importElementorItem(is_array($item) ? $item : [], $media_map, $remapper)) {
                $imported++;
            }
        }

        return [
            'type' => 'elementor',
            'imported' => $imported,
            'media_remapped' => count($media_map),
            'message' => sprintf(__('Imported %d Elementor pages/templates with %d remapped media items.', 'atlas-backup-migration'), $imported, count($media_map)),
        ];
    }

    /**
     * Imports theme files and options from a full package.
     *
     * @param array  $data Package manifest data.
     * @param string $work_dir Temporary extraction directory.
     * @return array
     */
    private function importTheme(array $data, string $work_dir): array
    {
        $theme = is_array($data['theme'] ?? null) ? $data['theme'] : [];
        $slug = sanitize_key($theme['slug'] ?? '');

        if ('' === $slug) {
            return [
                'type' => 'theme',
                'imported' => 0,
                'message' => __('Theme slug is missing from package.', 'atlas-backup-migration'),
            ];
        }

        $source = trailingslashit($work_dir) . 'files/theme';
        $target = trailingslashit(get_theme_root()) . $slug;

        if (is_dir($source)) {
            $this->copyDirectory($source, $target);
        }

        foreach ((array) ($theme['options'] ?? []) as $name => $value) {
            update_option(sanitize_key($name), $value);
        }

        if (! empty($theme['mods']) && is_array($theme['mods'])) {
            update_option('theme_mods_' . $slug, $theme['mods']);
        }

        return [
            'type' => 'theme',
            'imported' => 1,
            'message' => sprintf(__('Imported theme package for %s.', 'atlas-backup-migration'), $slug),
        ];
    }

    /**
     * Imports every packaged media file and returns an attachment ID map.
     *
     * @param string $work_dir Temporary extraction directory.
     * @param array  $media_metadata Source media metadata keyed by source ID.
     * @return array
     */
    private function importPackagedMedia(string $work_dir, array $media_metadata = []): array
    {
        $map = [];

        foreach ($media_metadata as $source_id => $metadata) {
            $source_id = absint($source_id);
            $attachment_id = $this->importSingleMedia($work_dir, $source_id, is_array($metadata) ? $metadata : []);

            if ($source_id && $attachment_id) {
                $map[$source_id] = $attachment_id;
            }
        }

        return $map;
    }

    /**
     * Collects media metadata from package-level and item-level payloads.
     *
     * @param array $data Package manifest data.
     * @return array
     */
    private function collectMediaMetadata(array $data): array
    {
        $media = [];
        $groups = [];

        if (! empty($data['media']) && is_array($data['media'])) {
            $groups[] = $data['media'];
        }

        foreach (['products', 'pages'] as $collection_key) {
            foreach ((array) ($data[$collection_key] ?? []) as $item) {
                if (is_array($item) && is_array($item['media'] ?? null)) {
                    $groups[] = $item['media'];
                }
            }
        }

        foreach ($groups as $group) {
            foreach ((array) $group as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $source_id = absint($item['source_id'] ?? 0);

                if ($source_id) {
                    $media[$source_id] = $item;
                }
            }
        }

        return $media;
    }

    /**
     * Safely extracts a ZIP package without using ZipArchive::extractTo.
     *
     * @param ZipArchive $zip Open ZIP archive.
     * @param string     $target Target extraction directory.
     * @throws RuntimeException If an unsafe path or write failure is detected.
     */
    private function safeExtract(ZipArchive $zip, string $target): void
    {
        $target = trailingslashit($target);
        wp_mkdir_p($target);
        $this->protectDirectory($target);

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->getNameIndex($index);

            if (! is_string($entry) || ! $this->isSafeZipEntry($entry)) {
                throw new RuntimeException(__('Import package contains an unsafe file path.', 'atlas-backup-migration'));
            }

            if ('/' === substr($entry, -1)) {
                $directory = $this->safeDestinationPath($target, $entry);
                wp_mkdir_p($directory);
                continue;
            }

            $destination = $this->safeDestinationPath($target, $entry);
            wp_mkdir_p(dirname($destination));
            $stream = $zip->getStream($entry);

            if (! is_resource($stream)) {
                continue;
            }

            $contents = stream_get_contents($stream);
            fclose($stream);

            if (false === $contents || false === file_put_contents($destination, $contents, LOCK_EX)) {
                throw new RuntimeException(__('Unable to write extracted import file.', 'atlas-backup-migration'));
            }
        }
    }

    /**
     * Copies a directory recursively into a target path.
     *
     * @param string $source Source directory.
     * @param string $target Target directory.
     */
    private function copyDirectory(string $source, string $target): void
    {
        wp_mkdir_p($target);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $destination = trailingslashit($target) . ltrim(str_replace(wp_normalize_path($source), '', wp_normalize_path($item->getPathname())), '/');

            if ($item->isDir()) {
                wp_mkdir_p($destination);
                continue;
            }

            copy($item->getPathname(), $destination);
        }
    }

    /**
     * Deletes a directory recursively and returns removed paths.
     *
     * @param string $directory Directory to delete.
     * @return array
     */
    private function cleanup(string $directory): array
    {
        $removed = [];

        if (! is_dir($directory)) {
            return $removed;
        }

        @chmod($directory, 0755);

        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = trailingslashit($directory) . $entry;

            if (is_link($path)) {
                if (@unlink($path)) {
                    $removed[] = basename($path);
                }
                continue;
            }

            if (is_dir($path)) {
                $removed = array_merge($removed, $this->cleanup($path));
                continue;
            }

            if (is_file($path)) {
                @chmod($path, 0644);
            }

            if (is_file($path) && @unlink($path)) {
                $removed[] = basename($path);
            }
        }

        if (is_writable($directory) && @rmdir($directory)) {
            $removed[] = basename($directory) . '/';
        }

        return $removed;
    }

    /**
     * Builds the protected imports base directory path.
     *
     * @return string
     */
    private function importsBaseDir(): string
    {
        return trailingslashit((new BackupRepository())->baseDir()) . 'imports';
    }

    /**
     * Creates an empty index.php and restrictive .htaccess in a directory.
     *
     * @param string $directory Directory to protect.
     */
    private function protectDirectory(string $directory): void
    {
        wp_mkdir_p($directory);

        $index = trailingslashit($directory) . 'index.php';
        $htaccess = trailingslashit($directory) . '.htaccess';

        if (! file_exists($index)) {
            file_put_contents($index, '', LOCK_EX);
        }

        if (! file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n", LOCK_EX);
        }
    }

    /**
     * Returns the initial transient state for a prepared import.
     *
     * @param string $session_id Import session UUID.
     * @param string $work_dir Temporary extraction directory.
     * @param string $package Stored package path.
     * @param array  $data Package manifest data.
     * @return array
     */
    private function defaultSessionState(string $session_id, string $work_dir, string $package, array $data): array
    {
        $media = $this->collectMediaMetadata($data);
        $items = $this->itemsForType($data);

        return [
            'session_id' => sanitize_key($session_id),
            'type' => sanitize_key($data['type'] ?? ''),
            'phase' => 'media',
            'work_dir' => $work_dir,
            'package' => $package,
            'data_path' => trailingslashit($work_dir) . 'data.json',
            'media_ids' => array_values(array_filter(array_map('absint', array_keys($media)))),
            'media_index' => 0,
            'item_index' => 0,
            'item_total' => count($items),
            'imported' => 0,
            'media_map' => [],
            'product_id_map' => [],
            'term_id_map' => [],
            'term_taxonomy_id_map' => [],
            'term_relationships_remapped' => 0,
            'variation_parents_remapped' => 0,
            'term_parents_remapped' => 0,
            'done' => false,
            'message' => '',
        ];
    }

    /**
     * Reads a resumable import session from WordPress transients.
     *
     * @param string $session_id Import session UUID.
     * @return array|WP_Error
     */
    private function getSession(string $session_id)
    {
        $session_id = sanitize_key($session_id);

        if ('' === $session_id) {
            return new WP_Error('abm_missing_import_session', __('Missing import session ID.', 'atlas-backup-migration'), ['status' => 400]);
        }

        $state = get_transient(self::TRANSIENT_PREFIX . $session_id);

        if (! is_array($state) || empty($state['work_dir'])) {
            return new WP_Error('abm_import_session_missing', __('Import session expired or was not found.', 'atlas-backup-migration'), ['status' => 404]);
        }

        if (! $this->isPathInside((string) $state['work_dir'], $this->importsBaseDir())) {
            return new WP_Error('abm_import_session_unsafe', __('Import session path is outside protected storage.', 'atlas-backup-migration'), ['status' => 403]);
        }

        return $state;
    }

    /**
     * Saves a resumable import session in WordPress transients.
     *
     * @param string $session_id Import session UUID.
     * @param array  $state Session state.
     */
    private function saveSession(string $session_id, array $state): void
    {
        set_transient(self::TRANSIENT_PREFIX . sanitize_key($session_id), $state, self::SESSION_TTL);
    }

    /**
     * Loads package data from the extracted data.json file.
     *
     * @param array $state Session state.
     * @return array|WP_Error
     */
    private function sessionData(array $state)
    {
        $path = (string) ($state['data_path'] ?? '');

        if (! $this->isPathInside($path, (string) $state['work_dir']) || ! is_readable($path)) {
            return new WP_Error('abm_import_data_missing', __('Import package data.json is missing.', 'atlas-backup-migration'), ['status' => 404]);
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || 'atlas-granular/v1' !== ($data['schema'] ?? '')) {
            return new WP_Error('abm_bad_import_package', __('Import package data.json is invalid.', 'atlas-backup-migration'), ['status' => 400]);
        }

        return $data;
    }

    /**
     * Processes the next packaged media chunk and persists the current media index.
     *
     * @param array $state Session state.
     * @param array $data Package manifest data.
     * @return array
     */
    private function processMediaChunk(array $state, array $data): array
    {
        $media_metadata = $this->collectMediaMetadata($data);
        $media_ids = array_values(array_filter(array_map('absint', $state['media_ids'] ?? [])));
        $index = absint($state['media_index'] ?? 0);
        $processed = 0;

        while ($index < count($media_ids) && $processed < self::MEDIA_BATCH_SIZE) {
            $source_id = absint($media_ids[$index]);

            if ($source_id && empty($state['media_map'][$source_id])) {
                $attachment_id = $this->importSingleMedia((string) $state['work_dir'], $source_id, is_array($media_metadata[$source_id] ?? null) ? $media_metadata[$source_id] : []);

                if ($attachment_id) {
                    $state['media_map'][$source_id] = $attachment_id;
                }
            }

            $index++;
            $processed++;
            $state['media_index'] = $index;
            $state['current_media_source_id'] = $source_id;
            $state['message'] = __('Importing packaged media...', 'atlas-backup-migration');
            $this->saveSession((string) $state['session_id'], $state);
        }

        $state['media_index'] = $index;

        if ($index >= count($media_ids)) {
            $state['phase'] = 'items';
        }

        $state['message'] = __('Importing packaged media...', 'atlas-backup-migration');
        $this->saveSession((string) $state['session_id'], $state);

        return $this->sessionResponse($state);
    }

    /**
     * Processes product, Elementor, or theme records and persists the current item index.
     *
     * @param array $state Session state.
     * @param array $data Package manifest data.
     * @return array|WP_Error
     */
    private function processItemChunk(array $state, array $data)
    {
        switch (sanitize_key($state['type'] ?? '')) {
            case 'woocommerce':
                $state = $this->processWooCommerceItems($state, $data);
                break;
            case 'elementor':
                $state = $this->processElementorItems($state, $data);
                break;
            case 'theme':
                $state = $this->processThemeItem($state, $data);
                break;
            default:
                return new WP_Error('abm_unknown_import_type', __('This granular package type is not supported.', 'atlas-backup-migration'), ['status' => 400]);
        }

        if (absint($state['item_index'] ?? 0) >= absint($state['item_total'] ?? 0)) {
            $state['phase'] = 'finalize';
        }

        $this->saveSession((string) $state['session_id'], $state);

        return $this->sessionResponse($state);
    }

    /**
     * Processes a batch of WooCommerce product payloads.
     *
     * @param array $state Session state.
     * @param array $data Package manifest data.
     * @return array
     */
    private function processWooCommerceItems(array $state, array $data): array
    {
        $items = array_values((array) ($data['products'] ?? []));
        $index = absint($state['item_index'] ?? 0);
        $processed = 0;
        $importer = new ProductImporter();

        while ($index < count($items) && $processed < self::ITEM_BATCH_SIZE) {
            $product = $items[$index];

            if (! is_array($product)) {
                $index++;
                $processed++;
                $state['item_index'] = $index;
                $state['message'] = __('Importing WooCommerce products...', 'atlas-backup-migration');
                $this->saveSession((string) $state['session_id'], $state);
                continue;
            }

            $result = $importer->import(
                $product,
                $this->sanitizeIdMap((array) ($state['media_map'] ?? [])),
                $this->sanitizeIdMap((array) ($state['product_id_map'] ?? [])),
                $this->sanitizeIdMap((array) ($state['term_id_map'] ?? [])),
                $this->sanitizeIdMap((array) ($state['term_taxonomy_id_map'] ?? [])),
                false
            );

            if (! is_wp_error($result)) {
                $state['imported'] = absint($state['imported'] ?? 0) + 1;
                $state['product_id_map'] = $this->sanitizeIdMap((array) ($result['product_id_map'] ?? $state['product_id_map']));
                $state['term_id_map'] = $this->sanitizeIdMap((array) ($result['term_id_map'] ?? $state['term_id_map']));
                $state['term_taxonomy_id_map'] = $this->sanitizeIdMap((array) ($result['term_taxonomy_id_map'] ?? $state['term_taxonomy_id_map']));
                $state['term_relationships_remapped'] = absint($state['term_relationships_remapped'] ?? 0) + absint($result['term_relationships_remapped'] ?? 0);
            }

            $index++;
            $processed++;
            $state['item_index'] = $index;
            $state['current_item_source_id'] = absint($product['source_id'] ?? 0);
            $state['message'] = __('Importing WooCommerce products...', 'atlas-backup-migration');
            $this->saveSession((string) $state['session_id'], $state);
        }

        $state['item_index'] = $index;
        $state['message'] = __('Importing WooCommerce products...', 'atlas-backup-migration');

        return $state;
    }

    /**
     * Processes a batch of Elementor page payloads.
     *
     * @param array $state Session state.
     * @param array $data Package manifest data.
     * @return array
     */
    private function processElementorItems(array $state, array $data): array
    {
        $items = array_values((array) ($data['pages'] ?? []));
        $index = absint($state['item_index'] ?? 0);
        $processed = 0;
        $remapper = new IdRemapper();
        $media_map = $this->sanitizeIdMap((array) ($state['media_map'] ?? []));

        while ($index < count($items) && $processed < self::ITEM_BATCH_SIZE) {
            $item = is_array($items[$index]) ? $items[$index] : [];

            if ($this->importElementorItem($item, $media_map, $remapper)) {
                $state['imported'] = absint($state['imported'] ?? 0) + 1;
            }

            $index++;
            $processed++;
            $state['item_index'] = $index;
            $state['current_item_source_id'] = absint($item['source_id'] ?? 0);
            $state['message'] = __('Importing Elementor pages/templates...', 'atlas-backup-migration');
            $this->saveSession((string) $state['session_id'], $state);
        }

        $state['item_index'] = $index;
        $state['message'] = __('Importing Elementor pages/templates...', 'atlas-backup-migration');

        return $state;
    }

    /**
     * Processes a theme package as a single resumable item.
     *
     * @param array $state Session state.
     * @param array $data Package manifest data.
     * @return array
     */
    private function processThemeItem(array $state, array $data): array
    {
        if (absint($state['item_index'] ?? 0) > 0) {
            return $state;
        }

        $result = $this->importTheme($data, (string) $state['work_dir']);
        $state['imported'] = absint($result['imported'] ?? 0);
        $state['item_index'] = 1;
        $state['item_total'] = 1;
        $state['message'] = (string) ($result['message'] ?? __('Imported theme package.', 'atlas-backup-migration'));

        return $state;
    }

    /**
     * Finalizes relationship remaps that need complete source-to-target maps.
     *
     * @param array $state Session state.
     * @param array $data Package manifest data.
     * @return array
     */
    private function finalizeSession(array $state, array $data): array
    {
        unset($data);

        if ('woocommerce' === sanitize_key($state['type'] ?? '')) {
            $importer = new ProductImporter();
            $state['variation_parents_remapped'] = $importer->remapProductParents($this->sanitizeIdMap((array) ($state['product_id_map'] ?? [])));
            $state['term_parents_remapped'] = $importer->remapTermParents(
                $this->sanitizeIdMap((array) ($state['term_id_map'] ?? [])),
                $this->sanitizeIdMap((array) ($state['term_taxonomy_id_map'] ?? []))
            );
        }

        $state['phase'] = 'done';
        $state['done'] = true;
        $state['message'] = $this->completeMessage($state);
        $this->saveSession((string) $state['session_id'], $state);

        return $this->sessionResponse($state);
    }

    /**
     * Imports a single packaged media item.
     *
     * @param string $work_dir Temporary extraction directory.
     * @param int    $source_id Source attachment ID.
     * @param array  $metadata Source attachment metadata.
     * @return int
     */
    private function importSingleMedia(string $work_dir, int $source_id, array $metadata): int
    {
        if (! $source_id) {
            return 0;
        }

        $existing_id = $this->findExistingAttachment($source_id);

        if ($existing_id) {
            return $existing_id;
        }

        $path = $this->mediaPathForSource($work_dir, $source_id);

        if ('' === $path || ! is_file($path) || ! is_readable($path)) {
            return 0;
        }

        $contents = file_get_contents($path);

        if (false === $contents) {
            return 0;
        }

        if (! empty($metadata['sha256']) && hash_file('sha256', $path) !== (string) $metadata['sha256']) {
            return 0;
        }

        $upload = wp_upload_bits(preg_replace('/^\d+-/', '', basename($path)), null, $contents);

        if (! empty($upload['error'])) {
            return 0;
        }

        $title = sanitize_text_field($metadata['title'] ?? pathinfo($upload['file'], PATHINFO_FILENAME));
        $mime_type = sanitize_mime_type((string) ($metadata['mime_type'] ?? '')) ?: (wp_check_filetype($upload['file'])['type'] ?: 'application/octet-stream');
        $attachment_id = wp_insert_attachment([
            'post_title' => $title,
            'post_mime_type' => $mime_type,
            'post_status' => 'inherit',
        ], $upload['file']);

        if (is_wp_error($attachment_id)) {
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata((int) $attachment_id, wp_generate_attachment_metadata((int) $attachment_id, $upload['file']));
        update_post_meta((int) $attachment_id, '_abm_source_attachment_id', $source_id);

        if (! empty($metadata['alt'])) {
            update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field($metadata['alt']));
        }

        if (! empty($metadata['sha256'])) {
            update_post_meta((int) $attachment_id, '_abm_source_attachment_sha256', sanitize_text_field($metadata['sha256']));
        }

        return (int) $attachment_id;
    }

    /**
     * Finds an already imported attachment by source attachment ID.
     *
     * @param int $source_id Source attachment ID.
     * @return int
     */
    private function findExistingAttachment(int $source_id): int
    {
        $matches = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'numberposts' => 1,
            'meta_key' => '_abm_source_attachment_id',
            'meta_value' => $source_id,
        ]);

        return $matches ? absint($matches[0]) : 0;
    }

    /**
     * Imports a single Elementor item and remaps referenced attachment IDs.
     *
     * @param array      $item Elementor item payload.
     * @param array      $media_map Source-to-target attachment map.
     * @param IdRemapper $remapper ID remapper instance.
     * @return bool
     */
    private function importElementorItem(array $item, array $media_map, IdRemapper $remapper): bool
    {
        $post_data = is_array($item['post'] ?? null) ? $item['post'] : [];

        if (empty($post_data['post_title'])) {
            return false;
        }

        $source_id = absint($item['source_id'] ?? 0);
        $existing_id = $this->findExistingImportedPost($source_id);
        $post_args = [
            'ID' => $existing_id,
            'post_type' => sanitize_key($post_data['post_type'] ?? 'page'),
            'post_status' => sanitize_key($post_data['post_status'] ?? 'draft'),
            'post_title' => sanitize_text_field($post_data['post_title']),
            'post_content' => wp_kses_post($post_data['post_content'] ?? ''),
            'post_excerpt' => wp_kses_post($post_data['post_excerpt'] ?? ''),
            'post_name' => sanitize_title($post_data['post_name'] ?? ''),
            'menu_order' => absint($post_data['menu_order'] ?? 0),
            'meta_input' => [
                '_abm_source_elementor_id' => $source_id,
                '_abm_source_post_id' => $source_id,
            ],
        ];
        $post_id = $existing_id ? wp_update_post($post_args, true) : wp_insert_post($post_args, true);

        if (is_wp_error($post_id)) {
            return false;
        }

        if ($source_id) {
            update_post_meta((int) $post_id, '_abm_source_elementor_id', $source_id);
            update_post_meta((int) $post_id, '_abm_source_post_id', $source_id);
        }

        foreach ((array) ($item['meta'] ?? []) as $key => $value) {
            $meta_key = sanitize_key($key);

            if ('' === $meta_key) {
                continue;
            }

            if ('_elementor_data' === $meta_key) {
                $value = $remapper->remapElementorJson($value, $media_map);
                $value = is_string($value) ? wp_slash($value) : $value;
            } else {
                $value = $remapper->remapValue($value, $media_map);
            }

            update_post_meta((int) $post_id, $meta_key, $value);
        }

        return true;
    }

    /**
     * Finds an imported post/page by the original source post ID.
     *
     * @param int $source_id Source post ID.
     * @return int Existing destination post ID or zero.
     */
    private function findExistingImportedPost(int $source_id): int
    {
        if (! $source_id) {
            return 0;
        }

        $matches = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => 1,
            'meta_key' => '_abm_source_post_id',
            'meta_value' => $source_id,
        ]);

        return $matches ? absint($matches[0]) : 0;
    }

    /**
     * Returns the extracted media path for a source attachment ID.
     *
     * @param string $work_dir Temporary extraction directory.
     * @param int    $source_id Source attachment ID.
     * @return string
     */
    private function mediaPathForSource(string $work_dir, int $source_id): string
    {
        $matches = glob(trailingslashit($work_dir) . 'media/' . absint($source_id) . '-*');

        if (! is_array($matches) || empty($matches)) {
            return '';
        }

        $path = (string) $matches[0];

        return $this->isPathInside($path, trailingslashit($work_dir) . 'media') ? $path : '';
    }

    /**
     * Returns the import item collection for the package type.
     *
     * @param array $data Package manifest data.
     * @return array
     */
    private function itemsForType(array $data): array
    {
        switch (sanitize_key($data['type'] ?? '')) {
            case 'woocommerce':
                return array_values((array) ($data['products'] ?? []));
            case 'elementor':
                return array_values((array) ($data['pages'] ?? []));
            case 'theme':
                return empty($data['theme']) ? [] : [$data['theme']];
            default:
                return [];
        }
    }

    /**
     * Builds the AJAX response for the current session state.
     *
     * @param array $state Session state.
     * @param array $extra Extra response fields.
     * @return array
     */
    private function sessionResponse(array $state, array $extra = []): array
    {
        $media_total = count((array) ($state['media_ids'] ?? []));
        $media_index = min($media_total, absint($state['media_index'] ?? 0));
        $item_total = absint($state['item_total'] ?? 0);
        $item_index = min($item_total, absint($state['item_index'] ?? 0));
        $total = max(1, $media_total + $item_total + 1);
        $processed = $media_index + $item_index + (! empty($state['done']) ? 1 : 0);
        $percent = ! empty($state['done']) ? 100 : min(99, (int) floor(($processed / $total) * 100));

        return array_merge([
            'session_id' => (string) ($state['session_id'] ?? ''),
            'type' => sanitize_key($state['type'] ?? ''),
            'phase' => sanitize_key($state['phase'] ?? ''),
            'done' => ! empty($state['done']),
            'percent' => $percent,
            'media_done' => $media_index,
            'media_total' => $media_total,
            'items_done' => $item_index,
            'items_total' => $item_total,
            'imported' => absint($state['imported'] ?? 0),
            'media_remapped' => count((array) ($state['media_map'] ?? [])),
            'products_remapped' => count((array) ($state['product_id_map'] ?? [])),
            'term_ids_remapped' => count((array) ($state['term_id_map'] ?? [])),
            'term_taxonomy_ids_remapped' => count((array) ($state['term_taxonomy_id_map'] ?? [])),
            'term_relationships_remapped' => absint($state['term_relationships_remapped'] ?? 0),
            'variation_parents_remapped' => absint($state['variation_parents_remapped'] ?? 0),
            'term_parents_remapped' => absint($state['term_parents_remapped'] ?? 0),
            'current_media_source_id' => absint($state['current_media_source_id'] ?? 0),
            'current_item_source_id' => absint($state['current_item_source_id'] ?? 0),
            'cleanup_available' => ! empty($state['done']),
            'message' => ! empty($state['message']) ? (string) $state['message'] : $this->progressMessage($state),
        ], $extra);
    }

    /**
     * Returns a localized progress message for an active session.
     *
     * @param array $state Session state.
     * @return string
     */
    private function progressMessage(array $state): string
    {
        switch ($state['phase'] ?? '') {
            case 'media':
                return __('Importing packaged media...', 'atlas-backup-migration');
            case 'items':
                return __('Importing package records...', 'atlas-backup-migration');
            case 'finalize':
                return __('Finalizing ID remapping...', 'atlas-backup-migration');
            case 'done':
                return $this->completeMessage($state);
            default:
                return __('Preparing import...', 'atlas-backup-migration');
        }
    }

    /**
     * Returns the localized completion message for a finished session.
     *
     * @param array $state Session state.
     * @return string
     */
    private function completeMessage(array $state): string
    {
        switch (sanitize_key($state['type'] ?? '')) {
            case 'woocommerce':
                return sprintf(
                    __('Imported %1$d WooCommerce products with %2$d remapped media items, %3$d taxonomy terms, and %4$d variation parents.', 'atlas-backup-migration'),
                    absint($state['imported'] ?? 0),
                    count((array) ($state['media_map'] ?? [])),
                    count((array) ($state['term_taxonomy_id_map'] ?? [])),
                    absint($state['variation_parents_remapped'] ?? 0)
                );
            case 'elementor':
                return sprintf(
                    __('Imported %1$d Elementor pages/templates with %2$d remapped media items.', 'atlas-backup-migration'),
                    absint($state['imported'] ?? 0),
                    count((array) ($state['media_map'] ?? []))
                );
            case 'theme':
                return __('Imported theme package.', 'atlas-backup-migration');
            default:
                return __('Import completed.', 'atlas-backup-migration');
        }
    }

    /**
     * Validates a ZIP entry as a relative path safe for extraction.
     *
     * @param string $entry ZIP entry name.
     * @return bool
     */
    private function isSafeZipEntry(string $entry): bool
    {
        $entry = str_replace('\\', '/', $entry);
        $entry_to_check = rtrim($entry, '/');

        if ('' === $entry_to_check || false !== strpos($entry, "\0") || 0 === strpos($entry, '/') || preg_match('/^[A-Za-z]:\\//', $entry)) {
            return false;
        }

        foreach (explode('/', $entry_to_check) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return false;
            }
        }

        return true;
    }

    /**
     * Builds a safe destination path under the extraction target.
     *
     * @param string $target Extraction target directory.
     * @param string $entry ZIP entry name.
     * @return string
     * @throws RuntimeException If the resolved path leaves the target directory.
     */
    private function safeDestinationPath(string $target, string $entry): string
    {
        $target = trailingslashit(wp_normalize_path(realpath($target) ?: $target));
        $path = wp_normalize_path($target . ltrim(str_replace('\\', '/', $entry), '/'));

        if (0 !== strpos($path, $target)) {
            throw new RuntimeException(__('Import package attempts to write outside the extraction directory.', 'atlas-backup-migration'));
        }

        return $path;
    }

    /**
     * Checks whether a filesystem path lives inside a base directory.
     *
     * @param string $path Candidate path.
     * @param string $base Base directory.
     * @return bool
     */
    private function isPathInside(string $path, string $base): bool
    {
        $base_real = trailingslashit(wp_normalize_path(realpath($base) ?: $base));
        $path_real = wp_normalize_path(realpath($path) ?: $path);

        return '' !== $path_real && 0 === strpos($path_real, $base_real);
    }
}
