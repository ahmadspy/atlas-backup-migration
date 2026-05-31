<?php
declare(strict_types=1);

$packageName = '{{PACKAGE_NAME}}';
$sqlName = '{{SQL_NAME}}';
$compatibilityName = 'compatibility-manifest.json';
$createdAt = '{{CREATED_AT}}';
$installerToken = hash('sha256', $packageName . '|' . $sqlName . '|' . $createdAt . '|' . __DIR__);

function abm_installer_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function abm_installer_require_post(): void
{
    if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
        http_response_code(405);
        exit('Method not allowed.');
    }
}

function abm_installer_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function abm_installer_success(array $data = []): void
{
    abm_installer_json(array_merge(['success' => true], $data));
}

function abm_installer_error(string $message, int $status = 400, array $data = []): void
{
    abm_installer_json(array_merge(['success' => false, 'message' => $message], $data), $status);
}

function abm_installer_request_string(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function abm_installer_state_path(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . '.atlas-installer-state.json';
}

function abm_installer_state(): array
{
    $path = abm_installer_state_path();

    if (! is_readable($path)) {
        return [];
    }

    $state = json_decode((string) @file_get_contents($path), true);

    return is_array($state) ? $state : [];
}

function abm_installer_save_state(array $state): void
{
    if (false === @file_put_contents(abm_installer_state_path(), (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
        throw new RuntimeException('Unable to write installer state. Check target directory permissions.');
    }
}

function abm_installer_update_state(array $changes): array
{
    $state = array_merge(abm_installer_state(), $changes);
    abm_installer_save_state($state);

    return $state;
}

function abm_installer_safe_path(string $base, string $path): string
{
    $target = rtrim(realpath($base) ?: $base, DIRECTORY_SEPARATOR);
    $path = str_replace('\\', '/', $path);
    $segments = [];

    foreach (explode('/', $path) as $segment) {
        if ('' === $segment || '.' === $segment || '..' === $segment) {
            continue;
        }

        $segments[] = $segment;
    }

    return $target . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
}

function abm_installer_memory_to_bytes(string $value): int
{
    $value = trim($value);

    if ('-1' === $value) {
        return -1;
    }

    if ('' === $value) {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    switch ($unit) {
        case 'g':
            $number *= 1024;
            // no break
        case 'm':
            $number *= 1024;
            // no break
        case 'k':
            $number *= 1024;
    }

    return (int) $number;
}

function abm_installer_format_bytes(int $bytes): string
{
    if ($bytes < 0) {
        return 'Unlimited';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float) $bytes;
    $unit = 0;

    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }

    return rtrim(rtrim(number_format($size, 2), '0'), '.') . ' ' . $units[$unit];
}

function abm_installer_package_path(string $packageName): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . basename($packageName);
}

function abm_installer_current_url(): string
{
    $https = (! empty($_SERVER['HTTPS']) && 'off' !== strtolower((string) $_SERVER['HTTPS'])) || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

    return ($https ? 'https://' : 'http://') . $host;
}

function abm_installer_sanitize_prefix(string $prefix): string
{
    $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $prefix);

    return '' === $prefix ? 'wp_' : $prefix;
}

function abm_installer_db_config_from_request(): array
{
    return [
        'host' => abm_installer_request_string('db_host', 'localhost'),
        'name' => abm_installer_request_string('db_name'),
        'user' => abm_installer_request_string('db_user'),
        'pass' => (string) ($_POST['db_pass'] ?? ''),
        'prefix' => abm_installer_sanitize_prefix(abm_installer_request_string('db_prefix', 'wp_')),
    ];
}

function abm_installer_connect(array $db): mysqli
{
    if (! class_exists('mysqli')) {
        throw new RuntimeException('The mysqli PHP extension is not available.');
    }

    if ('' === $db['host'] || '' === $db['name'] || '' === $db['user']) {
        throw new RuntimeException('Database host, name and user are required.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
    $mysqli->set_charset('utf8mb4');

    return $mysqli;
}

function abm_installer_extract_support_file(string $packageName, string $fileName): void
{
    if (! in_array($fileName, ['database.sql', 'compatibility-manifest.json'], true)) {
        return;
    }

    $target = __DIR__ . DIRECTORY_SEPARATOR . $fileName;

    if (is_file($target) || ! class_exists(ZipArchive::class) || ! is_file(abm_installer_package_path($packageName))) {
        return;
    }

    $zip = new ZipArchive();

    if (true === $zip->open(abm_installer_package_path($packageName))) {
        $stream = $zip->getStream($fileName);

        if (is_resource($stream)) {
            @file_put_contents($target, stream_get_contents($stream), LOCK_EX);
            fclose($stream);
        }

        $zip->close();
    }
}

function abm_installer_load_manifest(string $packageName, string $compatibilityName): array
{
    abm_installer_extract_support_file($packageName, $compatibilityName);

    $path = __DIR__ . DIRECTORY_SEPARATOR . $compatibilityName;

    if (! is_readable($path)) {
        return [];
    }

    $manifest = json_decode((string) @file_get_contents($path), true);

    return is_array($manifest) ? $manifest : [];
}

function abm_installer_system_checks(string $packageName): array
{
    $packagePath = abm_installer_package_path($packageName);
    $packageSize = is_file($packagePath) ? (int) (filesize($packagePath) ?: 0) : 0;
    $memory = ini_get('memory_limit') ?: '';
    $memoryBytes = abm_installer_memory_to_bytes($memory);
    $freeSpace = function_exists('disk_free_space') ? (int) (disk_free_space(__DIR__) ?: 0) : 0;
    $checks = [];

    $checks[] = [
        'label' => 'PHP version',
        'detail' => PHP_VERSION . ' detected; 7.4+ required.',
        'status' => version_compare(PHP_VERSION, '7.4', '>=') ? 'pass' : 'fail',
    ];

    $checks[] = [
        'label' => 'Memory limit',
        'detail' => $memory . ' configured; 128M+ recommended.',
        'status' => (-1 === $memoryBytes || $memoryBytes >= 128 * 1024 * 1024) ? 'pass' : 'warn',
    ];

    foreach (['zip' => 'ZipArchive package extraction', 'mysqli' => 'Database connection and import', 'json' => 'Installer state and manifest parsing'] as $extension => $detail) {
        $checks[] = [
            'label' => 'PHP extension: ' . $extension,
            'detail' => $detail,
            'status' => extension_loaded($extension) ? 'pass' : 'fail',
        ];
    }

    $checks[] = [
        'label' => 'Package file',
        'detail' => is_file($packagePath) ? basename($packagePath) . ' (' . abm_installer_format_bytes($packageSize) . ')' : basename($packagePath) . ' was not found next to installer.php.',
        'status' => is_file($packagePath) ? 'pass' : 'fail',
    ];

    $checks[] = [
        'label' => 'Write permissions',
        'detail' => is_writable(__DIR__) ? 'Target directory is writable.' : 'Target directory is not writable.',
        'status' => is_writable(__DIR__) ? 'pass' : 'fail',
    ];

    if ($freeSpace > 0 && $packageSize > 0) {
        $checks[] = [
            'label' => 'Disk space',
            'detail' => abm_installer_format_bytes($freeSpace) . ' free; package is ' . abm_installer_format_bytes($packageSize) . '.',
            'status' => $freeSpace > ($packageSize * 2) ? 'pass' : 'warn',
        ];
    }

    $canContinue = true;

    foreach ($checks as $check) {
        if ('fail' === $check['status']) {
            $canContinue = false;
            break;
        }
    }

    return [
        'checks' => $checks,
        'can_continue' => $canContinue,
    ];
}

function abm_installer_table_exists(mysqli $mysqli, string $table): bool
{
    $statement = $mysqli->prepare('SHOW TABLES LIKE ?');
    $statement->bind_param('s', $table);
    $statement->execute();
    $statement->store_result();
    $exists = $statement->num_rows > 0;
    $statement->close();

    return $exists;
}

function abm_installer_table_name(mysqli $mysqli, string $tableSuffix, string $dbPrefix, array $manifest): string
{
    $suffix = preg_replace('/[^A-Za-z0-9_]/', '', $tableSuffix);
    $candidates = array_values(array_unique(array_filter([
        preg_replace('/[^A-Za-z0-9_]/', '', $dbPrefix . $suffix),
        preg_replace('/[^A-Za-z0-9_]/', '', (string) ($manifest['db_prefix'] ?? '') . $suffix),
    ])));

    foreach ($candidates as $candidate) {
        if (abm_installer_table_exists($mysqli, $candidate)) {
            return $candidate;
        }
    }

    return $candidates[0] ?? '';
}

function abm_installer_column_exists(mysqli $mysqli, string $table, string $column): bool
{
    $statement = $mysqli->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $statement->bind_param('s', $column);
    $statement->execute();
    $statement->store_result();
    $exists = $statement->num_rows > 0;
    $statement->close();

    return $exists;
}

function abm_installer_primary_key(mysqli $mysqli, string $table): string
{
    $result = $mysqli->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
    $row = $result ? $result->fetch_assoc() : null;

    if ($result) {
        $result->free();
    }

    return (string) ($row['Column_name'] ?? '');
}

function abm_installer_replace_deep($value, string $oldUrl, string $newUrl)
{
    if (is_string($value)) {
        $unserialized = @unserialize($value, ['allowed_classes' => false]);

        if (false !== $unserialized || 'b:0;' === $value) {
            return serialize(abm_installer_replace_deep($unserialized, $oldUrl, $newUrl));
        }

        return str_replace($oldUrl, $newUrl, $value);
    }

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = abm_installer_replace_deep($item, $oldUrl, $newUrl);
        }

        return $value;
    }

    if (is_object($value)) {
        foreach (get_object_vars($value) as $key => $item) {
            $value->{$key} = abm_installer_replace_deep($item, $oldUrl, $newUrl);
        }
    }

    return $value;
}

function abm_installer_old_urls(array $manifest, string $oldUrl = ''): array
{
    $urls = [];

    foreach ([$oldUrl, (string) ($manifest['site_url'] ?? ''), (string) ($manifest['home_url'] ?? ''), (string) ($manifest['upload_baseurl'] ?? '')] as $url) {
        $url = rtrim(trim($url), '/');

        if ('' !== $url) {
            $urls[] = $url;

            if (0 === strpos($url, 'http://')) {
                $urls[] = 'https://' . substr($url, 7);
            } elseif (0 === strpos($url, 'https://')) {
                $urls[] = 'http://' . substr($url, 8);
            }
        }
    }

    return array_values(array_unique($urls));
}

function abm_installer_apply_url_replacements(string $value, array $oldUrls, string $newUrl): string
{
    $updated = $value;
    $newUrl = rtrim($newUrl, '/');

    foreach ($oldUrls as $oldUrl) {
        $updated = (string) abm_installer_replace_deep($updated, $oldUrl, $newUrl);
    }

    return $updated;
}

function abm_installer_escape_php_string(string $value): string
{
    return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
}

function abm_installer_update_wp_config(array $db): array
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'wp-config.php';

    if (! is_file($path)) {
        return ['updated' => false, 'message' => 'wp-config.php is not extracted yet.'];
    }

    if (! is_writable($path)) {
        throw new RuntimeException('wp-config.php is not writable. Update database constants manually and retry.');
    }

    $content = @file_get_contents($path);

    if (false === $content) {
        throw new RuntimeException('Unable to read wp-config.php.');
    }

    $constants = [
        'DB_NAME' => $db['name'],
        'DB_USER' => $db['user'],
        'DB_PASSWORD' => $db['pass'],
        'DB_HOST' => $db['host'],
    ];

    foreach ($constants as $name => $value) {
        $line = "define( '{$name}', '" . abm_installer_escape_php_string((string) $value) . "' );";
        $pattern = '/define\s*\(\s*[\'\"]' . preg_quote($name, '/') . '[\'\"]\s*,\s*[\'\"].*?[\'\"]\s*\)\s*;/';

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $line, $content, 1);
        } else {
            $content = preg_replace('/<\?php\s*/', "<?php\n" . $line . "\n", $content, 1);
        }
    }

    $prefixLine = "$" . "table_prefix = '" . abm_installer_escape_php_string($db['prefix']) . "';";

    if (preg_match('/^\s*\$table_prefix\s*=\s*[\'\"].*?[\'\"]\s*;/m', $content)) {
        $content = preg_replace('/^\s*\$table_prefix\s*=\s*[\'\"].*?[\'\"]\s*;/m', $prefixLine, $content, 1);
    } else {
        $content .= "\n" . $prefixLine . "\n";
    }

    if (false === @file_put_contents($path, $content, LOCK_EX)) {
        throw new RuntimeException('Unable to write wp-config.php.');
    }

    return ['updated' => true, 'message' => 'wp-config.php was updated for the target database.'];
}

function abm_installer_rewrite_sql_prefix(string $sql, string $sourcePrefix, string $targetPrefix): string
{
    $sourcePrefix = abm_installer_sanitize_prefix($sourcePrefix);
    $targetPrefix = abm_installer_sanitize_prefix($targetPrefix);

    if ($sourcePrefix === $targetPrefix || '' === $sourcePrefix) {
        return $sql;
    }

    return (string) preg_replace_callback('/`' . preg_quote($sourcePrefix, '/') . '([A-Za-z0-9_]+)`/', static function (array $matches) use ($targetPrefix): string {
        return '`' . $targetPrefix . $matches[1] . '`';
    }, $sql);
}

function abm_installer_sql_statement_complete(string $sql): bool
{
    $quote = '';
    $escape = false;
    $semicolon = -1;
    $length = strlen($sql);

    for ($index = 0; $index < $length; $index++) {
        $char = $sql[$index];

        if ('' !== $quote) {
            if ($escape) {
                $escape = false;
                continue;
            }

            if ('\\' === $char) {
                $escape = true;
                continue;
            }

            if ($char === $quote) {
                $quote = '';
            }

            continue;
        }

        if ('\'' === $char || '"' === $char) {
            $quote = $char;
            continue;
        }

        if (';' === $char) {
            $semicolon = $index;
        }
    }

    return $semicolon >= 0 && '' === trim(substr($sql, $semicolon + 1));
}

function abm_installer_execute_sql_statement(mysqli $mysqli, string $statement, array $manifest, string $targetPrefix): void
{
    $statement = trim($statement);

    if ('' === $statement || 0 === strpos($statement, '--') || 0 === strpos($statement, '#')) {
        return;
    }

    $sourcePrefix = (string) ($manifest['db_prefix'] ?? $targetPrefix);
    $statement = abm_installer_rewrite_sql_prefix($statement, $sourcePrefix, $targetPrefix);
    $mysqli->query($statement);
}

function abm_installer_regenerate_elementor_css(): string
{
    $wpLoad = __DIR__ . DIRECTORY_SEPARATOR . 'wp-load.php';

    if (! is_file($wpLoad)) {
        return 'wp-load.php was not found; regenerate Elementor CSS from WordPress admin.';
    }

    require_once $wpLoad;

    if (! class_exists('\\Elementor\\Plugin')) {
        return 'Elementor is not active; CSS regeneration skipped.';
    }

    $elementor = \Elementor\Plugin::$instance;

    if (isset($elementor->files_manager) && method_exists($elementor->files_manager, 'clear_cache')) {
        $elementor->files_manager->clear_cache();
    }

    if (isset($elementor->posts_css_manager) && method_exists($elementor->posts_css_manager, 'clear_cache')) {
        $elementor->posts_css_manager->clear_cache();
    }

    if (isset($elementor->frontend) && method_exists($elementor->frontend, 'get_builder_content_for_display') && function_exists('get_posts')) {
        $posts = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'meta_key' => '_elementor_data',
            'fields' => 'ids',
            'numberposts' => -1,
        ]);

        foreach ($posts as $postId) {
            if (class_exists('\\Elementor\\Core\\Files\\CSS\\Post')) {
                $postCss = new \Elementor\Core\Files\CSS\Post((int) $postId);

                if (method_exists($postCss, 'update')) {
                    $postCss->update();
                }
            }

            $elementor->frontend->get_builder_content_for_display((int) $postId, true);
        }
    }

    return 'Elementor CSS cache was cleared and regeneration was requested.';
}

function abm_installer_ajax_system_check(string $packageName, string $compatibilityName): void
{
    $manifest = abm_installer_load_manifest($packageName, $compatibilityName);
    $checks = abm_installer_system_checks($packageName);

    abm_installer_success([
        'checks' => $checks['checks'],
        'can_continue' => $checks['can_continue'],
        'manifest' => [
            'site_url' => (string) ($manifest['site_url'] ?? ''),
            'home_url' => (string) ($manifest['home_url'] ?? ''),
            'db_prefix' => (string) ($manifest['db_prefix'] ?? 'wp_'),
            'package' => basename($packageName),
        ],
        'current_url' => abm_installer_current_url(),
    ]);
}

function abm_installer_ajax_test_db(string $packageName, string $compatibilityName): void
{
    $manifest = abm_installer_load_manifest($packageName, $compatibilityName);
    $db = abm_installer_db_config_from_request();
    $mysqli = abm_installer_connect($db);
    $mysqli->query('CREATE TEMPORARY TABLE abm_installer_connection_test (id INT NOT NULL)');
    $version = $mysqli->server_info;
    $mysqli->close();

    abm_installer_update_state([
        'db_tested' => true,
        'db_prefix' => $db['prefix'],
        'source_prefix' => (string) ($manifest['db_prefix'] ?? $db['prefix']),
    ]);

    abm_installer_success([
        'message' => 'Connection successful. Write privileges are available.',
        'server' => $version,
        'source_prefix' => (string) ($manifest['db_prefix'] ?? $db['prefix']),
        'target_prefix' => $db['prefix'],
    ]);
}

function abm_installer_ajax_extract_prepare(string $packageName, string $compatibilityName, string $sqlName): void
{
    if (! class_exists(ZipArchive::class)) {
        throw new RuntimeException('PHP ZipArchive extension is not available.');
    }

    $zip = new ZipArchive();

    if (true !== $zip->open(abm_installer_package_path($packageName))) {
        throw new RuntimeException('Unable to open migration package.');
    }

    $total = 0;

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entry = $zip->getNameIndex($index);

        if (is_string($entry) && 0 === strpos($entry, 'site/') && 'site/' !== $entry) {
            $total++;
        }
    }

    $zip->close();
    abm_installer_extract_support_file($packageName, $compatibilityName);
    abm_installer_extract_support_file($packageName, $sqlName);

    abm_installer_update_state([
        'extract' => [
            'index' => 0,
            'processed' => 0,
            'total' => $total,
            'done' => false,
        ],
    ]);

    abm_installer_success([
        'total' => $total,
        'processed' => 0,
        'percent' => 0,
        'message' => 'Extraction prepared.',
    ]);
}

function abm_installer_ajax_extract_chunk(string $packageName): void
{
    $state = abm_installer_state();
    $extract = is_array($state['extract'] ?? null) ? $state['extract'] : ['index' => 0, 'processed' => 0, 'total' => 0, 'done' => false];

    if (! empty($extract['done'])) {
        abm_installer_success($extract + ['percent' => 100]);
    }

    $zip = new ZipArchive();

    if (true !== $zip->open(abm_installer_package_path($packageName))) {
        throw new RuntimeException('Unable to open migration package.');
    }

    $index = (int) ($extract['index'] ?? 0);
    $processed = (int) ($extract['processed'] ?? 0);
    $total = (int) ($extract['total'] ?? 0);
    $chunkCount = 0;
    $maxPerChunk = 45;

    while ($index < $zip->numFiles && $chunkCount < $maxPerChunk) {
        $entry = $zip->getNameIndex($index);
        $index++;

        if (! is_string($entry) || 0 !== strpos($entry, 'site/') || 'site/' === $entry) {
            continue;
        }

        $relative = substr($entry, 5);

        if ('' === $relative || false !== strpos($relative, '../') || false !== strpos($relative, '..\\')) {
            $processed++;
            $chunkCount++;
            continue;
        }

        $target = abm_installer_safe_path(__DIR__, $relative);

        if ('/' === substr($entry, -1)) {
            if (! is_dir($target)) {
                if (! @mkdir($target, 0755, true) && ! is_dir($target)) {
                    throw new RuntimeException('Unable to create directory: ' . $relative);
                }
            }
            $processed++;
            $chunkCount++;
            continue;
        }

        $directory = dirname($target);

        if (! is_dir($directory)) {
            if (! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create directory: ' . dirname($relative));
            }
        }

        $stream = $zip->getStream($entry);

        if (is_resource($stream)) {
            if (false === @file_put_contents($target, stream_get_contents($stream), LOCK_EX)) {
                fclose($stream);
                throw new RuntimeException('Unable to write extracted file: ' . $relative);
            }
            fclose($stream);
        }

        $processed++;
        $chunkCount++;
    }

    $done = $index >= $zip->numFiles;
    $zip->close();

    $extract = [
        'index' => $index,
        'processed' => $processed,
        'total' => $total,
        'done' => $done,
    ];

    $state['extract'] = $extract;
    abm_installer_save_state($state);

    abm_installer_success($extract + [
        'percent' => $total > 0 ? min(100, (int) floor(($processed / $total) * 100)) : 100,
        'message' => $done ? 'Files extracted successfully.' : 'Extracting files...',
    ]);
}

function abm_installer_ajax_import_prepare(string $packageName, string $compatibilityName, string $sqlName): void
{
    $db = abm_installer_db_config_from_request();
    $manifest = abm_installer_load_manifest($packageName, $compatibilityName);
    abm_installer_extract_support_file($packageName, $sqlName);
    $sqlPath = __DIR__ . DIRECTORY_SEPARATOR . $sqlName;

    if (! is_file($sqlPath)) {
        throw new RuntimeException('database.sql was not found.');
    }

    $mysqli = abm_installer_connect($db);
    $mysqli->query('SET FOREIGN_KEY_CHECKS=0');
    $mysqli->close();
    $config = abm_installer_update_wp_config($db);

    abm_installer_update_state([
        'import' => [
            'offset' => 0,
            'processed_bytes' => 0,
            'total_bytes' => (int) (filesize($sqlPath) ?: 0),
            'statements' => 0,
            'done' => false,
            'target_prefix' => $db['prefix'],
            'source_prefix' => (string) ($manifest['db_prefix'] ?? $db['prefix']),
        ],
    ]);

    abm_installer_success([
        'message' => 'Database import prepared. ' . $config['message'],
        'config' => $config,
        'total_bytes' => (int) (filesize($sqlPath) ?: 0),
        'percent' => 0,
    ]);
}

function abm_installer_ajax_import_chunk(string $packageName, string $compatibilityName, string $sqlName): void
{
    $db = abm_installer_db_config_from_request();
    $manifest = abm_installer_load_manifest($packageName, $compatibilityName);
    $state = abm_installer_state();
    $import = is_array($state['import'] ?? null) ? $state['import'] : [];
    $sqlPath = __DIR__ . DIRECTORY_SEPARATOR . $sqlName;

    if (! is_file($sqlPath)) {
        throw new RuntimeException('database.sql was not found.');
    }

    if (! empty($import['done'])) {
        abm_installer_success($import + ['percent' => 100]);
    }

    $mysqli = abm_installer_connect($db);
    $mysqli->query('SET FOREIGN_KEY_CHECKS=0');
    $handle = fopen($sqlPath, 'rb');

    if (! is_resource($handle)) {
        $mysqli->close();
        throw new RuntimeException('Unable to read database.sql.');
    }

    $offset = max(0, (int) ($import['offset'] ?? 0));
    $totalBytes = (int) (filesize($sqlPath) ?: 0);
    $statements = (int) ($import['statements'] ?? 0);
    $executed = 0;
    $statement = '';
    $deadline = microtime(true) + 3.0;
    fseek($handle, $offset);

    while (! feof($handle) && $executed < 90 && microtime(true) < $deadline) {
        $line = fgets($handle);

        if (false === $line) {
            break;
        }

        $currentOffset = (int) ftell($handle);
        $trimmed = trim($line);

        if ('' === $statement && ('' === $trimmed || 0 === strpos($trimmed, '--') || 0 === strpos($trimmed, '#'))) {
            $offset = $currentOffset;
            continue;
        }

        $statement .= $line;

        if (abm_installer_sql_statement_complete($statement)) {
            abm_installer_execute_sql_statement($mysqli, $statement, $manifest, $db['prefix']);
            $statement = '';
            $executed++;
            $statements++;
            $offset = $currentOffset;
        }
    }

    if (feof($handle) && '' !== trim($statement)) {
        if (! abm_installer_sql_statement_complete($statement)) {
            fclose($handle);
            $mysqli->close();
            throw new RuntimeException('The SQL dump ended with an incomplete statement.');
        }

        abm_installer_execute_sql_statement($mysqli, $statement, $manifest, $db['prefix']);
        $offset = (int) ftell($handle);
        $statements++;
        $executed++;
        $statement = '';
    }

    $done = feof($handle) && '' === trim($statement);

    if ($done) {
        $mysqli->query('SET FOREIGN_KEY_CHECKS=1');
    }

    fclose($handle);
    $mysqli->close();

    $import = [
        'offset' => $offset,
        'processed_bytes' => min($offset, $totalBytes),
        'total_bytes' => $totalBytes,
        'statements' => $statements,
        'done' => $done,
        'target_prefix' => $db['prefix'],
        'source_prefix' => (string) ($manifest['db_prefix'] ?? $db['prefix']),
    ];

    $state['import'] = $import;
    abm_installer_save_state($state);

    abm_installer_success($import + [
        'executed' => $executed,
        'percent' => $totalBytes > 0 ? min(100, (int) floor(($offset / $totalBytes) * 100)) : 100,
        'message' => $done ? 'Database import completed.' : 'Importing database statements...',
    ]);
}

function abm_installer_ajax_rewrite_prepare(string $packageName, string $compatibilityName): void
{
    $db = abm_installer_db_config_from_request();
    $newUrl = rtrim(abm_installer_request_string('new_url', abm_installer_current_url()), '/');
    $oldUrl = abm_installer_request_string('old_url');

    if ('' === $newUrl) {
        throw new RuntimeException('New site URL is required.');
    }

    $manifest = abm_installer_load_manifest($packageName, $compatibilityName);
    $oldUrls = abm_installer_old_urls($manifest, $oldUrl);

    if ([] === $oldUrls) {
        throw new RuntimeException('Source URL was not found in the manifest. Enter it manually.');
    }

    $mysqli = abm_installer_connect($db);
    $columns = is_array($manifest['url_rewrite']['columns'] ?? null) ? $manifest['url_rewrite']['columns'] : [
        'options' => ['option_value'],
        'postmeta' => ['meta_value'],
        'posts' => ['post_content', 'guid'],
        'termmeta' => ['meta_value'],
        'usermeta' => ['meta_value'],
    ];
    $tasks = [];
    $total = 0;

    foreach ($columns as $tableSuffix => $columnNames) {
        $table = abm_installer_table_name($mysqli, (string) $tableSuffix, $db['prefix'], $manifest);

        if ('' === $table || ! abm_installer_table_exists($mysqli, $table)) {
            continue;
        }

        $primary = abm_installer_primary_key($mysqli, $table);

        if ('' === $primary) {
            continue;
        }

        foreach ((array) $columnNames as $columnName) {
            $column = preg_replace('/[^A-Za-z0-9_]/', '', (string) $columnName);

            if ('' === $column || ! abm_installer_column_exists($mysqli, $table, $column)) {
                continue;
            }

            $result = $mysqli->query("SELECT COUNT(*) AS total FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` <> ''");
            $row = $result ? $result->fetch_assoc() : ['total' => 0];

            if ($result) {
                $result->free();
            }

            $count = (int) ($row['total'] ?? 0);
            $tasks[] = [
                'table' => $table,
                'primary' => $primary,
                'column' => $column,
                'offset' => 0,
                'total' => $count,
                'updated' => 0,
            ];
            $total += $count;
        }
    }

    $mysqli->close();

    abm_installer_update_state([
        'rewrite' => [
            'tasks' => $tasks,
            'index' => 0,
            'processed' => 0,
            'total' => $total,
            'updated' => 0,
            'done' => false,
            'old_urls' => $oldUrls,
            'new_url' => $newUrl,
        ],
    ]);

    abm_installer_success([
        'tasks' => count($tasks),
        'total' => $total,
        'processed' => 0,
        'updated' => 0,
        'percent' => 0,
        'message' => 'Search and replace prepared.',
    ]);
}

function abm_installer_ajax_rewrite_chunk(): void
{
    $db = abm_installer_db_config_from_request();
    $state = abm_installer_state();
    $rewrite = is_array($state['rewrite'] ?? null) ? $state['rewrite'] : [];

    if (! empty($rewrite['done'])) {
        abm_installer_success($rewrite + ['percent' => 100]);
    }

    $tasks = is_array($rewrite['tasks'] ?? null) ? $rewrite['tasks'] : [];
    $index = (int) ($rewrite['index'] ?? 0);
    $processed = (int) ($rewrite['processed'] ?? 0);
    $total = (int) ($rewrite['total'] ?? 0);
    $updated = (int) ($rewrite['updated'] ?? 0);
    $oldUrls = is_array($rewrite['old_urls'] ?? null) ? $rewrite['old_urls'] : [];
    $newUrl = (string) ($rewrite['new_url'] ?? '');

    if ([] === $tasks || [] === $oldUrls || '' === $newUrl) {
        throw new RuntimeException('Search and replace has not been prepared.');
    }

    $mysqli = abm_installer_connect($db);
    $deadline = microtime(true) + 3.0;
    $chunkSize = 160;

    while ($index < count($tasks) && microtime(true) < $deadline) {
        $task = $tasks[$index];
        $remaining = (int) $task['total'] - (int) $task['offset'];

        if ($remaining <= 0) {
            $index++;
            continue;
        }

        $limit = min($chunkSize, $remaining);
        $offset = (int) $task['offset'];
        $table = $task['table'];
        $primary = $task['primary'];
        $column = $task['column'];
        $result = $mysqli->query("SELECT `{$primary}`, `{$column}` FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` <> '' LIMIT {$limit} OFFSET {$offset}");
        $rows = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }

            $result->free();
        }

        foreach ($rows as $row) {
            $value = (string) $row[$column];
            $newValue = abm_installer_apply_url_replacements($value, $oldUrls, $newUrl);

            if ($newValue !== $value) {
                $statement = $mysqli->prepare("UPDATE `{$table}` SET `{$column}` = ? WHERE `{$primary}` = ?");
                $primaryValue = (string) $row[$primary];
                $statement->bind_param('ss', $newValue, $primaryValue);
                $statement->execute();
                $statement->close();
                $task['updated'] = (int) $task['updated'] + 1;
                $updated++;
            }
        }

        $count = count($rows);
        $task['offset'] = (int) $task['offset'] + $count;
        $processed += $count;
        $tasks[$index] = $task;

        if ($count < $limit || (int) $task['offset'] >= (int) $task['total']) {
            $index++;
        }

        if ($count <= 0) {
            break;
        }
    }

    $mysqli->close();
    $done = $index >= count($tasks);
    $rewrite = array_merge($rewrite, [
        'tasks' => $tasks,
        'index' => $index,
        'processed' => min($processed, $total),
        'total' => $total,
        'updated' => $updated,
        'done' => $done,
    ]);
    $state['rewrite'] = $rewrite;
    abm_installer_save_state($state);

    abm_installer_success($rewrite + [
        'percent' => $total > 0 ? min(100, (int) floor(($processed / $total) * 100)) : 100,
        'message' => $done ? 'Search and replace completed.' : 'Scanning database rows...',
    ]);
}

function abm_installer_load_wordpress(): void
{
    $wpLoad = __DIR__ . DIRECTORY_SEPARATOR . 'wp-load.php';

    if (! is_file($wpLoad)) {
        throw new RuntimeException('wp-load.php was not found. Extract files and import the database first.');
    }

    require_once $wpLoad;
}

function abm_installer_ajax_admin_config(): void
{
    abm_installer_load_wordpress();

    $messages = [];
    $siteTitle = abm_installer_request_string('site_title');

    if ('' !== $siteTitle) {
        update_option('blogname', function_exists('wp_strip_all_tags') ? wp_strip_all_tags($siteTitle) : strip_tags($siteTitle));
        $messages[] = 'Site title updated.';
    }

    $newUser = abm_installer_request_string('new_admin_user');
    $newEmail = abm_installer_request_string('new_admin_email');
    $newPass = (string) ($_POST['new_admin_pass'] ?? '');

    if ('' !== $newUser || '' !== $newEmail || '' !== $newPass) {
        if ('' === $newUser || '' === $newEmail || '' === $newPass) {
            throw new RuntimeException('Username, email and password are required to create a new admin.');
        }

        if (username_exists($newUser)) {
            throw new RuntimeException('The requested admin username already exists.');
        }

        if (email_exists($newEmail)) {
            throw new RuntimeException('The requested admin email already exists.');
        }

        $userId = wp_create_user($newUser, $newPass, $newEmail);

        if (is_wp_error($userId)) {
            throw new RuntimeException($userId->get_error_message());
        }

        $user = get_user_by('id', (int) $userId);

        if ($user) {
            $user->set_role('administrator');
        }

        $messages[] = 'New administrator account created.';
    }

    $resetLogin = abm_installer_request_string('reset_admin_login');
    $resetPass = (string) ($_POST['reset_admin_pass'] ?? '');

    if ('' !== $resetLogin || '' !== $resetPass) {
        if ('' === $resetLogin || '' === $resetPass) {
            throw new RuntimeException('Existing admin login/email and new password are required for password reset.');
        }

        $user = false !== strpos($resetLogin, '@') ? get_user_by('email', $resetLogin) : get_user_by('login', $resetLogin);

        if (! $user) {
            throw new RuntimeException('Existing admin account was not found.');
        }

        wp_set_password($resetPass, (int) $user->ID);
        $messages[] = 'Existing admin password updated.';
    }

    if (! empty($_POST['regenerate_elementor'])) {
        $messages[] = abm_installer_regenerate_elementor_css();
    }

    if ([] === $messages) {
        $messages[] = 'No admin or configuration changes were requested.';
    }

    abm_installer_success(['messages' => $messages]);
}

function abm_installer_ajax_cleanup(string $packageName, string $sqlName, string $compatibilityName): void
{
    $files = [
        abm_installer_package_path($packageName),
        __DIR__ . DIRECTORY_SEPARATOR . $sqlName,
        __DIR__ . DIRECTORY_SEPARATOR . $compatibilityName,
        abm_installer_state_path(),
    ];
    $self = __FILE__;
    $removed = [];
    $failed = [];

    foreach (array_unique($files) as $file) {
        if (is_file($file)) {
            if (@unlink($file)) {
                $removed[] = basename($file);
            } else {
                $failed[] = basename($file);
            }
        }
    }

    if (is_file($self)) {
        if (@unlink($self)) {
            $removed[] = basename($self);
        } else {
            $failed[] = basename($self);
        }
    }

    abm_installer_success([
        'message' => 'Cleanup completed. This installer cannot be reused after refresh.',
        'removed' => $removed,
        'failed' => $failed,
    ]);
}

if (isset($_POST['abm_ajax'])) {
    abm_installer_require_post();

    if (! hash_equals($installerToken, (string) ($_POST['token'] ?? ''))) {
        abm_installer_error('Invalid installer token.', 403);
    }

    try {
        $action = abm_installer_request_string('action');

        switch ($action) {
            case 'system_check':
                abm_installer_ajax_system_check($packageName, $compatibilityName);
                break;
            case 'test_db':
                abm_installer_ajax_test_db($packageName, $compatibilityName);
                break;
            case 'extract_prepare':
                abm_installer_ajax_extract_prepare($packageName, $compatibilityName, $sqlName);
                break;
            case 'extract_chunk':
                abm_installer_ajax_extract_chunk($packageName);
                break;
            case 'import_prepare':
                abm_installer_ajax_import_prepare($packageName, $compatibilityName, $sqlName);
                break;
            case 'import_chunk':
                abm_installer_ajax_import_chunk($packageName, $compatibilityName, $sqlName);
                break;
            case 'rewrite_prepare':
                abm_installer_ajax_rewrite_prepare($packageName, $compatibilityName);
                break;
            case 'rewrite_chunk':
                abm_installer_ajax_rewrite_chunk();
                break;
            case 'admin_config':
                abm_installer_ajax_admin_config();
                break;
            case 'cleanup':
                abm_installer_ajax_cleanup($packageName, $sqlName, $compatibilityName);
                break;
            default:
                abm_installer_error('Unknown installer action.', 400);
        }
    } catch (Throwable $exception) {
        abm_installer_error($exception->getMessage(), 500);
    }
}

$initialManifest = abm_installer_load_manifest($packageName, $compatibilityName);
$initialChecks = abm_installer_system_checks($packageName);
$sourceUrl = (string) ($initialManifest['site_url'] ?? '');
$sourcePrefix = (string) ($initialManifest['db_prefix'] ?? 'wp_');
$currentUrl = abm_installer_current_url();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Atlas Standalone Installer</title>
    <style>
        :root{--ink:#14213d;--muted:#6b7280;--paper:#fffaf0;--panel:rgba(255,255,255,.82);--line:rgba(20,33,61,.14);--gold:#fca311;--ember:#e85d04;--mint:#2a9d8f;--sky:#277da1;--bad:#d62828;--shadow:0 26px 80px rgba(20,33,61,.16);--radius:28px}
        *{box-sizing:border-box}body{min-height:100vh;margin:0;color:var(--ink);font-family:"Aptos Display","Satoshi","Segoe UI",sans-serif;background:radial-gradient(circle at 10% 10%,rgba(252,163,17,.32),transparent 28%),radial-gradient(circle at 86% 18%,rgba(42,157,143,.28),transparent 30%),linear-gradient(135deg,#fff7e1 0%,#f7fbff 45%,#ffe9d6 100%);overflow-x:hidden}.grain{position:fixed;inset:0;pointer-events:none;opacity:.42;background-image:linear-gradient(rgba(20,33,61,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(20,33,61,.035) 1px,transparent 1px);background-size:34px 34px;mask-image:linear-gradient(to bottom,#000,transparent 92%)}.shell{width:min(1180px,calc(100% - 32px));margin:0 auto;padding:32px 0 48px}.hero{position:relative;display:grid;grid-template-columns:1.2fr .8fr;gap:22px;align-items:end;margin-bottom:22px;padding:28px;border:1px solid var(--line);border-radius:34px;background:linear-gradient(135deg,rgba(255,255,255,.88),rgba(255,255,255,.52));box-shadow:var(--shadow);backdrop-filter:blur(18px);overflow:hidden}.hero:before{content:"";position:absolute;right:-80px;top:-120px;width:340px;height:340px;border-radius:50%;background:conic-gradient(from 140deg,var(--gold),var(--mint),var(--sky),var(--gold));opacity:.28}.eyebrow{display:inline-flex;align-items:center;gap:8px;width:max-content;padding:8px 12px;border:1px solid var(--line);border-radius:999px;background:#fff;font-size:12px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#8a4b00}.hero h1{max-width:760px;margin:16px 0 10px;font-size:clamp(38px,7vw,82px);line-height:.9;letter-spacing:-.07em}.hero p{max-width:680px;margin:0;color:#48556a;font-size:16px;line-height:1.7}.hero-meta{position:relative;display:grid;gap:10px}.meta-card{padding:16px;border:1px solid var(--line);border-radius:22px;background:rgba(255,255,255,.78)}.meta-card span{display:block;color:var(--muted);font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}.meta-card strong{display:block;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.workspace{display:grid;grid-template-columns:310px minmax(0,1fr);gap:22px}.stepper{position:sticky;top:18px;align-self:start;padding:18px;border:1px solid var(--line);border-radius:var(--radius);background:rgba(255,255,255,.74);box-shadow:var(--shadow);backdrop-filter:blur(18px)}.step{display:grid;grid-template-columns:42px 1fr;gap:12px;width:100%;padding:12px;border:0;border-radius:20px;background:transparent;color:var(--ink);text-align:left;cursor:pointer}.step+.step{margin-top:6px}.step:hover,.step.is-active{background:#fff4da}.step.is-done .num{background:var(--mint);color:#fff}.num{display:grid;place-items:center;width:42px;height:42px;border-radius:15px;background:#f1e4ca;color:#8a4b00;font-weight:1000}.step-title{display:block;margin-top:2px;font-weight:1000}.step-desc{display:block;margin-top:3px;color:var(--muted);font-size:12px;line-height:1.35}.panel{display:none;border:1px solid var(--line);border-radius:var(--radius);background:var(--panel);box-shadow:var(--shadow);backdrop-filter:blur(18px);overflow:hidden}.panel.is-active{display:block;animation:rise .35s ease both}@keyframes rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}.panel-head{padding:28px 30px 18px;border-bottom:1px solid var(--line);background:linear-gradient(135deg,rgba(255,255,255,.85),rgba(255,250,240,.58))}.panel-head h2{margin:0;font-size:30px;letter-spacing:-.04em}.panel-head p{margin:8px 0 0;color:#526071;line-height:1.65}.panel-body{padding:26px 30px 30px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.card{padding:18px;border:1px solid var(--line);border-radius:22px;background:rgba(255,255,255,.72)}.check-list{display:grid;gap:10px}.check{display:grid;grid-template-columns:38px 1fr auto;gap:12px;align-items:center;padding:14px;border:1px solid var(--line);border-radius:18px;background:#fff}.dot{display:grid;place-items:center;width:38px;height:38px;border-radius:13px;background:#eef2f7;color:#64748b;font-weight:1000}.check.pass .dot{background:#dff7ef;color:#087f5b}.check.warn .dot{background:#fff2c6;color:#a16207}.check.fail .dot{background:#ffe0e0;color:var(--bad)}.check strong{display:block}.check small{display:block;margin-top:3px;color:var(--muted);line-height:1.35}.badge{display:inline-flex;align-items:center;height:28px;padding:0 10px;border-radius:999px;background:#edf2f7;color:#526071;font-size:12px;font-weight:1000}.pass .badge{background:#dff7ef;color:#087f5b}.warn .badge{background:#fff2c6;color:#a16207}.fail .badge{background:#ffe0e0;color:var(--bad)}label{display:grid;gap:8px;font-weight:950;color:#26364f}.hint{color:var(--muted);font-size:12px;line-height:1.45}input{width:100%;min-height:48px;border:1px solid rgba(20,33,61,.18);border-radius:16px;background:#fff;padding:0 14px;color:var(--ink);font:inherit;font-weight:700;outline:none;transition:.18s}input:focus{border-color:var(--gold);box-shadow:0 0 0 4px rgba(252,163,17,.16)}.actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:20px}.btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:48px;padding:0 18px;border:0;border-radius:16px;background:#18233a;color:#fff;font-weight:1000;cursor:pointer;box-shadow:0 14px 28px rgba(20,33,61,.18);transition:.18s}.btn:hover{transform:translateY(-1px)}.btn:disabled{cursor:not-allowed;opacity:.48;transform:none}.btn.secondary{background:#fff;color:var(--ink);border:1px solid var(--line);box-shadow:none}.btn.gold{background:linear-gradient(135deg,var(--gold),var(--ember))}.btn.green{background:linear-gradient(135deg,var(--mint),#1f7a8c)}.btn.danger{background:linear-gradient(135deg,#ef233c,#9d0208)}.progress-wrap{display:grid;gap:10px;margin:14px 0}.progress-top{display:flex;align-items:center;justify-content:space-between;color:#526071;font-size:13px;font-weight:900}.bar{height:14px;border-radius:999px;background:#edf2f7;overflow:hidden}.bar span{display:block;width:0%;height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--mint),var(--gold),var(--ember));transition:width .25s ease}.log{min-height:46px;margin-top:14px;padding:14px 16px;border:1px solid var(--line);border-radius:18px;background:#111827;color:#dbeafe;font:13px/1.5 "Cascadia Code","JetBrains Mono",monospace;white-space:pre-wrap}.notice{display:none;margin-top:16px;padding:14px 16px;border-radius:18px;font-weight:800;line-height:1.5}.notice.show{display:block}.notice.ok{background:#dff7ef;color:#087f5b}.notice.err{background:#ffe0e0;color:#9d0208}.notice.info{background:#e7f2ff;color:#1d4e89}.split{display:grid;grid-template-columns:1fr 1fr;gap:16px}.toggle{display:flex;align-items:center;gap:10px;padding:14px;border:1px solid var(--line);border-radius:18px;background:#fff;font-weight:900}.toggle input{width:18px;min-height:18px}.mini{font-size:12px;color:var(--muted);line-height:1.5}.cleanup-box{border:1px dashed rgba(214,40,40,.45);background:rgba(255,224,224,.5)}@media(max-width:960px){.hero,.workspace,.split{grid-template-columns:1fr}.stepper{position:relative;top:auto}.grid,.grid-3{grid-template-columns:1fr}.hero h1{font-size:46px}}@media(max-width:560px){.shell{width:min(100% - 18px,1180px);padding-top:14px}.hero,.panel-head,.panel-body{padding:20px}.stepper{padding:12px}.actions .btn{width:100%}}
    </style>
</head>
<body>
<div class="grain"></div>
<main class="shell">
    <section class="hero">
        <div>
            <span class="eyebrow">Atlas standalone restore</span>
            <h1>Launch the site from a clean restore path.</h1>
            <p>This installer is self-contained. It checks the server, tests the target database, extracts the package, imports SQL with progress, rewrites URLs safely, manages admins and removes itself when finished.</p>
        </div>
        <div class="hero-meta">
            <div class="meta-card"><span>Package</span><strong><?php echo abm_installer_h(basename($packageName)); ?></strong></div>
            <div class="meta-card"><span>Source URL</span><strong id="sourceUrlMeta"><?php echo abm_installer_h($sourceUrl ?: 'Pending manifest'); ?></strong></div>
            <div class="meta-card"><span>Created</span><strong><?php echo abm_installer_h($createdAt); ?></strong></div>
        </div>
    </section>

    <section class="workspace">
        <aside class="stepper" aria-label="Installer steps">
            <button class="step is-active" data-step="0"><span class="num">1</span><span><span class="step-title">System Check</span><span class="step-desc">PHP, extensions, memory and permissions</span></span></button>
            <button class="step" data-step="1"><span class="num">2</span><span><span class="step-title">Database Setup</span><span class="step-desc">Connect and verify write access</span></span></button>
            <button class="step" data-step="2"><span class="num">3</span><span><span class="step-title">Extract & Import</span><span class="step-desc">Restore files and SQL with progress</span></span></button>
            <button class="step" data-step="3"><span class="num">4</span><span><span class="step-title">Search & Replace</span><span class="step-desc">Serialized-safe URL migration</span></span></button>
            <button class="step" data-step="4"><span class="num">5</span><span><span class="step-title">Admin & Config</span><span class="step-desc">Site title, admin user and passwords</span></span></button>
            <button class="step" data-step="5"><span class="num">6</span><span><span class="step-title">Cleanup</span><span class="step-desc">Delete backup files and installer</span></span></button>
        </aside>

        <div>
            <section class="panel is-active" data-panel="0">
                <div class="panel-head"><h2>System Check</h2><p>Resolve failed checks before continuing. Warnings are safe to review, but may affect large restores.</p></div>
                <div class="panel-body">
                    <div id="checks" class="check-list"></div>
                    <div id="systemNotice" class="notice"></div>
                    <div class="actions"><button class="btn gold" id="runChecks">Run Checks Again</button><button class="btn" id="toDb" disabled>Continue to Database</button></div>
                </div>
            </section>

            <section class="panel" data-panel="1">
                <div class="panel-head"><h2>Database Setup</h2><p>Enter the target database details. The installer runs an AJAX connection test and verifies write privileges before continuing.</p></div>
                <div class="panel-body">
                    <div class="grid">
                        <label>Host<input id="dbHost" value="localhost" autocomplete="off"></label>
                        <label>Database Name<input id="dbName" autocomplete="off"></label>
                        <label>Database User<input id="dbUser" autocomplete="off"></label>
                        <label>Database Password<input id="dbPass" type="password" autocomplete="new-password"></label>
                        <label>Target Table Prefix<input id="dbPrefix" value="<?php echo abm_installer_h($sourcePrefix ?: 'wp_'); ?>"><span class="hint">If changed, imported SQL table names and wp-config.php are remapped to this prefix.</span></label>
                        <label>Detected Source Prefix<input value="<?php echo abm_installer_h($sourcePrefix ?: 'wp_'); ?>" disabled></label>
                    </div>
                    <div id="dbNotice" class="notice"></div>
                    <div class="actions"><button class="btn gold" id="testDb">Test Connection</button><button class="btn" id="toExtract" disabled>Continue to Restore</button></div>
                </div>
            </section>

            <section class="panel" data-panel="2">
                <div class="panel-head"><h2>Extraction & DB Import</h2><p>Files are extracted from <strong>site/</strong> and SQL is imported statement-by-statement so the progress bars reflect real work.</p></div>
                <div class="panel-body split">
                    <div class="card">
                        <h3>Extract Files</h3>
                        <p class="mini">Existing files can be overwritten. Keep this browser tab open.</p>
                        <div class="progress-wrap"><div class="progress-top"><span id="extractText">Waiting</span><span id="extractPct">0%</span></div><div class="bar"><span id="extractBar"></span></div></div>
                        <button class="btn gold" id="extractBtn">Start Extraction</button>
                    </div>
                    <div class="card">
                        <h3>Import Database</h3>
                        <p class="mini">The installer also updates wp-config.php with the tested database credentials.</p>
                        <div class="progress-wrap"><div class="progress-top"><span id="importText">Waiting</span><span id="importPct">0%</span></div><div class="bar"><span id="importBar"></span></div></div>
                        <button class="btn green" id="importBtn" disabled>Start DB Import</button>
                    </div>
                    <div class="log" id="restoreLog">Ready.</div>
                    <div id="restoreNotice" class="notice"></div>
                    <div class="actions"><button class="btn" id="toRewrite" disabled>Continue to Search & Replace</button></div>
                </div>
            </section>

            <section class="panel" data-panel="3">
                <div class="panel-head"><h2>Search & Replace</h2><p>Update old URLs to the target URL across posts, postmeta, options and other manifest-defined columns while preserving serialized data lengths.</p></div>
                <div class="panel-body">
                    <div class="grid">
                        <label>Old Site URL<input id="oldUrl" value="<?php echo abm_installer_h($sourceUrl); ?>" placeholder="https://old-domain.com"><span class="hint">Manifest URLs are also included automatically.</span></label>
                        <label>New Site URL<input id="newUrl" value="<?php echo abm_installer_h($currentUrl); ?>" placeholder="https://new-domain.com"></label>
                    </div>
                    <div class="progress-wrap"><div class="progress-top"><span id="rewriteText">Waiting</span><span id="rewritePct">0%</span></div><div class="bar"><span id="rewriteBar"></span></div></div>
                    <div id="rewriteNotice" class="notice"></div>
                    <div class="actions"><button class="btn gold" id="rewriteBtn">Run Search & Replace</button><button class="btn" id="toAdmin" disabled>Continue to Admin & Config</button></div>
                </div>
            </section>

            <section class="panel" data-panel="4">
                <div class="panel-head"><h2>Admin & Config Management</h2><p>Finalize the restored WordPress site by updating the title, creating a safe admin account, resetting an existing admin password, or regenerating Elementor CSS.</p></div>
                <div class="panel-body">
                    <div class="grid">
                        <label>New Site Title<input id="siteTitle" placeholder="Optional"></label>
                        <label>Reset Existing Admin Login/Email<input id="resetAdminLogin" placeholder="admin or admin@example.com"></label>
                        <label>New Admin Username<input id="newAdminUser" placeholder="Optional"></label>
                        <label>New Admin Email<input id="newAdminEmail" type="email" placeholder="Optional"></label>
                        <label>New Admin Password<input id="newAdminPass" type="password" autocomplete="new-password" placeholder="Optional"></label>
                        <label>Existing Admin New Password<input id="resetAdminPass" type="password" autocomplete="new-password" placeholder="Optional"></label>
                    </div>
                    <label class="toggle"><input id="regenElementor" type="checkbox"> Regenerate Elementor CSS cache after config changes</label>
                    <div id="adminNotice" class="notice"></div>
                    <div class="actions"><button class="btn green" id="adminBtn">Apply Final Settings</button><button class="btn" id="toCleanup">Continue to Cleanup</button></div>
                </div>
            </section>

            <section class="panel" data-panel="5">
                <div class="panel-head"><h2>Cleanup</h2><p>Remove the archive, temporary SQL/manifest files, installer state and this installer. Do this only after confirming the restored site works.</p></div>
                <div class="panel-body">
                    <div class="card cleanup-box"><strong>Security reminder</strong><p class="mini">Leaving installer.php or the backup package on a public server is dangerous. Cleanup deletes only installer artifacts, not the restored WordPress files.</p></div>
                    <div id="cleanupNotice" class="notice"></div>
                    <div class="actions"><button class="btn danger" id="cleanupBtn">Delete Backup Files & Installer</button></div>
                </div>
            </section>
        </div>
    </section>
</main>
<script>
(function(){
    const cfg = {
        token: <?php echo json_encode($installerToken); ?>,
        initialChecks: <?php echo json_encode($initialChecks['checks'], JSON_UNESCAPED_SLASHES); ?>,
        canContinue: <?php echo $initialChecks['can_continue'] ? 'true' : 'false'; ?>
    };
    const $ = (selector) => document.querySelector(selector);
    const $$ = (selector) => Array.from(document.querySelectorAll(selector));
    const completed = new Set();
    let dbReady = false;
    let extracted = false;
    let imported = false;

    function esc(value){return String(value || '').replace(/[&<>'"]/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));}
    function notice(el, type, message){el.className = 'notice show ' + type; el.textContent = message;}
    function setBusy(button, busy, label){button.disabled = busy; if (busy){button.dataset.label = button.textContent; button.textContent = label || 'Working...';} else if (button.dataset.label){button.textContent = button.dataset.label; delete button.dataset.label;}}
    function setProgress(name, percent, text){percent = Math.max(0, Math.min(100, Number(percent || 0))); $('#' + name + 'Bar').style.width = percent + '%'; $('#' + name + 'Pct').textContent = percent + '%'; if (text){$('#' + name + 'Text').textContent = text;}}
    function dbPayload(){return {db_host:$('#dbHost').value,db_name:$('#dbName').value,db_user:$('#dbUser').value,db_pass:$('#dbPass').value,db_prefix:$('#dbPrefix').value};}
    function formBody(action, data){const body = new URLSearchParams(); body.set('abm_ajax','1'); body.set('token',cfg.token); body.set('action',action); Object.keys(data || {}).forEach((key) => body.set(key, data[key])); return body;}
    async function api(action, data){const res = await fetch(location.href, {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:formBody(action,data || {})}); const json = await res.json().catch(() => ({success:false,message:'Invalid JSON response from installer.'})); if (!json.success){throw new Error(json.message || 'Installer request failed.');} return json;}
    function go(step){$$('.panel').forEach((panel) => panel.classList.toggle('is-active', Number(panel.dataset.panel) === step)); $$('.step').forEach((item) => item.classList.toggle('is-active', Number(item.dataset.step) === step)); window.scrollTo({top:0,behavior:'smooth'});}
    function done(step){completed.add(step); const item = $('.step[data-step="' + step + '"]'); if (item){item.classList.add('is-done');}}
    function renderChecks(checks){$('#checks').innerHTML = checks.map((check) => '<div class="check '+esc(check.status)+'"><span class="dot">'+(check.status === 'pass' ? 'OK' : check.status === 'warn' ? '!' : 'X')+'</span><span><strong>'+esc(check.label)+'</strong><small>'+esc(check.detail)+'</small></span><span class="badge">'+esc(check.status)+'</span></div>').join('');}

    async function runChecks(){const button = $('#runChecks'); setBusy(button,true,'Checking...'); try{const data = await api('system_check'); renderChecks(data.checks || []); cfg.canContinue = !!data.can_continue; $('#toDb').disabled = !cfg.canContinue; if (data.manifest && data.manifest.site_url){$('#sourceUrlMeta').textContent = data.manifest.site_url; $('#oldUrl').value = $('#oldUrl').value || data.manifest.site_url;} if (data.manifest && data.manifest.db_prefix){$('#dbPrefix').value = data.manifest.db_prefix;} notice($('#systemNotice'), cfg.canContinue ? 'ok' : 'err', cfg.canContinue ? 'All required checks passed.' : 'Resolve failed checks before continuing.'); if (cfg.canContinue){done(0);} }catch(error){notice($('#systemNotice'),'err',error.message);} finally{setBusy(button,false);}}

    async function testDb(){const button = $('#testDb'); setBusy(button,true,'Testing...'); try{const data = await api('test_db', dbPayload()); dbReady = true; $('#toExtract').disabled = false; done(1); notice($('#dbNotice'),'ok', data.message + ' MySQL: ' + (data.server || 'unknown'));}catch(error){dbReady = false; $('#toExtract').disabled = true; notice($('#dbNotice'),'err',error.message);} finally{setBusy(button,false);}}

    async function extract(){const button = $('#extractBtn'); setBusy(button,true,'Extracting...'); try{const prep = await api('extract_prepare'); setProgress('extract',0,'0 of ' + (prep.total || 0) + ' entries'); $('#restoreLog').textContent = 'Extraction prepared.\n'; let data = prep; do{data = await api('extract_chunk'); setProgress('extract',data.percent,(data.processed || 0) + ' of ' + (data.total || 0) + ' entries'); $('#restoreLog').textContent = data.message + '\nProcessed entries: ' + (data.processed || 0) + '/' + (data.total || 0); }while(!data.done); extracted = true; $('#importBtn').disabled = !dbReady; done(2); notice($('#restoreNotice'),'ok','Files extracted. You can now import the database.');}catch(error){notice($('#restoreNotice'),'err',error.message);} finally{setBusy(button,false);}}

    async function importDb(){const button = $('#importBtn'); if (!dbReady){notice($('#restoreNotice'),'err','Test the database connection first.'); return;} setBusy(button,true,'Importing...'); try{const prep = await api('import_prepare', dbPayload()); setProgress('import',0,'0 bytes'); $('#restoreLog').textContent = prep.message + '\n'; let data = prep; do{data = await api('import_chunk', dbPayload()); setProgress('import',data.percent,(data.processed_bytes || 0) + ' of ' + (data.total_bytes || 0) + ' bytes'); $('#restoreLog').textContent = data.message + '\nStatements executed: ' + (data.statements || 0); }while(!data.done); imported = true; $('#toRewrite').disabled = false; done(2); notice($('#restoreNotice'),'ok','Database import completed.');}catch(error){notice($('#restoreNotice'),'err',error.message);} finally{setBusy(button,false);}}

    async function rewrite(){const button = $('#rewriteBtn'); if (!imported && !confirm('Database import is not marked complete in this browser. Continue anyway?')){return;} setBusy(button,true,'Rewriting...'); try{let payload = Object.assign({}, dbPayload(), {old_url:$('#oldUrl').value,new_url:$('#newUrl').value}); const prep = await api('rewrite_prepare', payload); setProgress('rewrite',0,'0 of ' + (prep.total || 0) + ' rows'); let data = prep; do{data = await api('rewrite_chunk', dbPayload()); setProgress('rewrite',data.percent,(data.processed || 0) + ' of ' + (data.total || 0) + ' rows'); }while(!data.done); $('#toAdmin').disabled = false; done(3); notice($('#rewriteNotice'),'ok','Search and replace completed. Rows updated: ' + (data.updated || 0));}catch(error){notice($('#rewriteNotice'),'err',error.message);} finally{setBusy(button,false);}}

    async function adminConfig(){const button = $('#adminBtn'); setBusy(button,true,'Applying...'); try{const data = await api('admin_config', {site_title:$('#siteTitle').value,new_admin_user:$('#newAdminUser').value,new_admin_email:$('#newAdminEmail').value,new_admin_pass:$('#newAdminPass').value,reset_admin_login:$('#resetAdminLogin').value,reset_admin_pass:$('#resetAdminPass').value,regenerate_elementor:$('#regenElementor').checked ? '1' : ''}); done(4); notice($('#adminNotice'),'ok',(data.messages || []).join(' '));}catch(error){notice($('#adminNotice'),'err',error.message);} finally{setBusy(button,false);}}

    async function cleanup(){if (!confirm('Delete the backup package, temporary files and installer.php? This cannot be undone.')){return;} const button = $('#cleanupBtn'); setBusy(button,true,'Cleaning...'); try{const data = await api('cleanup'); done(5); notice($('#cleanupNotice'),'ok',data.message + ' Removed: ' + ((data.removed || []).join(', ') || 'none') + (data.failed && data.failed.length ? '. Failed: ' + data.failed.join(', ') : ''));}catch(error){notice($('#cleanupNotice'),'err',error.message);} finally{setBusy(button,false);}}

    renderChecks(cfg.initialChecks || []);
    $('#toDb').disabled = !cfg.canContinue;
    if (cfg.canContinue){notice($('#systemNotice'),'ok','Initial required checks passed.'); done(0);} else {notice($('#systemNotice'),'err','One or more required checks failed.');}
    $$('.step').forEach((item) => item.addEventListener('click', () => go(Number(item.dataset.step))));
    $('#runChecks').addEventListener('click', runChecks);
    $('#toDb').addEventListener('click', () => go(1));
    $('#testDb').addEventListener('click', testDb);
    $('#toExtract').addEventListener('click', () => go(2));
    $('#extractBtn').addEventListener('click', extract);
    $('#importBtn').addEventListener('click', importDb);
    $('#toRewrite').addEventListener('click', () => go(3));
    $('#rewriteBtn').addEventListener('click', rewrite);
    $('#toAdmin').addEventListener('click', () => go(4));
    $('#adminBtn').addEventListener('click', adminConfig);
    $('#toCleanup').addEventListener('click', () => {done(4); go(5);});
    $('#cleanupBtn').addEventListener('click', cleanup);
})();
</script>
</body>
</html>
