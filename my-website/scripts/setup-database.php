<?php
declare(strict_types=1);

// Run with PHP CLI. Database administrator credentials never enter the web app.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

set_error_handler(static function (int $severity): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException('A local setup operation failed.', 0, $severity);
});

$stage = 'configuration';
$administrator = null;
$createdAccount = null;
$configWritten = false;
$temporaryPath = null;

/** Encode a single-line value for the local INI-style environment file. */
function databaseEnvValue(string $value): string
{
    if (strpbrk($value, "\r\n\0") !== false) {
        throw new RuntimeException('Database settings must be single-line values.');
    }

    // INI_SCANNER_RAW retains backslashes and interior quotes literally.
    $encoded = '"' . $value . '"';
    $roundTrip = @parse_ini_string('value=' . $encoded, false, INI_SCANNER_RAW);
    if ($roundTrip === false || ($roundTrip['value'] ?? null) !== $value) {
        throw new RuntimeException('A database setting could not be saved without changing its value.');
    }
    return $encoded;
}

/** Replace only the five database settings; every unrelated byte is retained. */
function writeDatabaseSettings(string $workspace, array $settings, ?string &$temporaryPath): string
{
    $target = $workspace . DIRECTORY_SEPARATOR . '.env.local';
    if (is_link($target) || (file_exists($target) && !is_file($target))) {
        throw new RuntimeException('The local configuration target is not a regular file.');
    }

    if (file_exists($target)) {
        $resolved = realpath($target);
        if ($resolved === false || strcasecmp(dirname($resolved), $workspace) !== 0) {
            throw new RuntimeException('The local configuration target is outside the workspace.');
        }
    }

    $original = file_exists($target) ? file_get_contents($target) : '';
    if ($original === false) {
        throw new RuntimeException('The local configuration target could not be read.');
    }

    preg_match('/\r\n|\n|\r/', $original, $newlineMatch);
    $newline = $newlineMatch[0] ?? PHP_EOL;
    $seen = [];
    $updated = preg_replace_callback(
        '/(?m)^[\t ]*(?:export[\t ]+)?(DB_HOST|DB_PORT|DB_NAME|DB_USER|DB_PASSWORD)[\t ]*=[^\r\n]*/',
        static function (array $match) use ($settings, &$seen): string {
            $name = $match[1];
            $seen[$name] = true;
            return $name . '=' . databaseEnvValue($settings[$name]);
        },
        $original
    );
    if ($updated === null) {
        throw new RuntimeException('The local database settings could not be prepared.');
    }

    $missing = array_diff_key($settings, $seen);
    if ($missing !== []) {
        if ($updated !== '' && !str_ends_with($updated, "\n") && !str_ends_with($updated, "\r")) {
            $updated .= $newline;
        }
        foreach ($missing as $name => $value) {
            $updated .= $name . '=' . databaseEnvValue($value) . $newline;
        }
    }

    if ($updated === $original) {
        return $target;
    }

    // The sibling name is covered by the repository and Apache .env.* rules.
    $temporaryPath = $target . '.tmp.' . bin2hex(random_bytes(8));
    $stream = fopen($temporaryPath, 'x+b');
    if ($stream === false) {
        throw new RuntimeException('A local configuration write could not be started.');
    }
    try {
        chmod($temporaryPath, 0600);
        $offset = 0;
        while ($offset < strlen($updated)) {
            $written = fwrite($stream, substr($updated, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('The local configuration write could not be completed.');
            }
            $offset += $written;
        }
        fflush($stream);
        if (function_exists('fsync')) {
            fsync($stream);
        }
    } finally {
        fclose($stream);
    }

    if (is_link($target)) {
        throw new RuntimeException('The local configuration target changed during setup.');
    }
    $current = file_exists($target) ? file_get_contents($target) : '';
    if ($current !== $original) {
        throw new RuntimeException('The local configuration changed during setup.');
    }
    if (!rename($temporaryPath, $target)) {
        throw new RuntimeException('The local configuration could not be replaced.');
    }
    $temporaryPath = null;

    return $target;
}

try {
    $workspace = realpath(dirname(__DIR__));
    if ($workspace === false) {
        throw new RuntimeException('The application workspace could not be resolved.');
    }
    require_once $workspace . '/includes/config.php';
    $config = loadLocalConfig();

    $host = configValue($config, 'DB_HOST', '127.0.0.1');
    $port = configValue($config, 'DB_PORT', '3306');
    $database = configValue($config, 'DB_NAME', 'kayasure_chat');
    if (!preg_match('/^[A-Za-z0-9_.:\\-]+$/D', $host)
        || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
        || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database)) {
        throw new RuntimeException('The database connection settings are invalid.');
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ];
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
    $adminUser = getenv('DB_SETUP_USER');
    $adminPassword = getenv('DB_SETUP_PASSWORD');
    $stage = 'administrator connection';
    $administrator = new PDO(
        $dsn,
        $adminUser !== false && $adminUser !== '' ? $adminUser : 'root',
        $adminPassword !== false ? $adminPassword : '',
        $options
    );
    $administrator->exec("SET time_zone = '+00:00'");

    $stage = 'database and schema';
    $administrator->exec('CREATE DATABASE IF NOT EXISTS `' . $database
        . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $administrator->exec('USE `' . $database . '`');
    $schema = file_get_contents($workspace . '/database/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('The schema file could not be read.');
    }
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) as $statement) {
        if (trim($statement) !== '') {
            $administrator->exec($statement);
        }
    }

    // CREATE TABLE IF NOT EXISTS does not add columns to existing installations.
    // Add only missing fields/indexes; existing account rows and credentials stay intact.
    $stage = 'account schema migration';
    $columnExists = $administrator->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $accountColumns = [
        'username' => 'VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NULL AFTER id',
        'storage_key' => 'CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER username',
    ];
    foreach ($accountColumns as $column => $definition) {
        $columnExists->execute([$database, 'users', $column]);
        if ((int) $columnExists->fetchColumn() === 0) {
            // Both identifiers and definitions come exclusively from the static map above.
            $administrator->exec('ALTER TABLE users ADD COLUMN `' . $column . '` ' . $definition);
        }
    }
    $indexExists = $administrator->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    foreach (['uq_users_username' => 'username', 'uq_users_storage_key' => 'storage_key'] as $index => $column) {
        $indexExists->execute([$database, 'users', $index]);
        if ((int) $indexExists->fetchColumn() === 0) {
            $administrator->exec('ALTER TABLE users ADD UNIQUE KEY `' . $index . '` (`' . $column . '`)');
        }
    }

    $stage = 'runtime database account';
    $runtimeUser = configValue($config, 'DB_USER');
    $runtimePassword = configValue($config, 'DB_PASSWORD');
    if ($runtimeUser === '') {
        $runtimeUser = 'kayasure_app';
        $accountExists = $administrator->prepare(
            "SELECT COUNT(*) FROM mysql.user WHERE User = ? AND Host = '127.0.0.1'"
        );
        do {
            $accountExists->execute([$runtimeUser]);
            if ((int) $accountExists->fetchColumn() === 0) {
                break;
            }
            $runtimeUser = 'kayasure_app_' . bin2hex(random_bytes(4));
        } while (true);

        $runtimePassword = bin2hex(random_bytes(24));
        $accountSql = $administrator->quote($runtimeUser) . "@'127.0.0.1'";
        $administrator->exec('CREATE USER ' . $accountSql . ' IDENTIFIED BY '
            . $administrator->quote($runtimePassword));
        $createdAccount = $accountSql;
        $administrator->exec('GRANT SELECT, INSERT, UPDATE ON `' . $database . '`.* TO ' . $accountSql);
    }

    // Configured accounts are reused as-is; an existing password is never rotated.
    $stage = 'runtime connection verification';
    $runtime = new PDO($dsn . ';dbname=' . $database, $runtimeUser, $runtimePassword, $options);
    $runtime->exec("SET time_zone = '+00:00'");
    $runtime->query('SELECT id FROM chat_conversations LIMIT 0');
    $runtime->query('SELECT id FROM chat_messages LIMIT 0');
    $runtime->query('SELECT id, username, storage_key FROM users LIMIT 0');
    $runtime->query('SELECT id FROM auth_challenges LIMIT 0');
    $runtime->query('SELECT key_hash FROM auth_rate_limits LIMIT 0');

    $stage = 'local database configuration';
    $configPath = writeDatabaseSettings($workspace, [
        'DB_HOST' => $host,
        'DB_PORT' => $port,
        'DB_NAME' => $database,
        'DB_USER' => $runtimeUser,
        'DB_PASSWORD' => $runtimePassword,
    ], $temporaryPath);
    $configWritten = true;

    echo json_encode([
        'database' => $database,
        'host' => $host,
        'port' => (int) $port,
        'runtime_user' => $runtimeUser,
        'tables' => ['chat_conversations', 'chat_messages', 'users', 'auth_challenges', 'auth_rate_limits'],
        'configuration_path' => $configPath,
        'status' => 'ready',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    if ($temporaryPath !== null && is_file($temporaryPath) && !is_link($temporaryPath)) {
        @unlink($temporaryPath);
    }
    if ($createdAccount !== null && !$configWritten && $administrator instanceof PDO) {
        try {
            $administrator->exec('DROP USER IF EXISTS ' . $createdAccount);
        } catch (Throwable) {
            // Never print SQL text or authentication values during cleanup.
        }
    }
    $diagnostic = ['error' => 'Database setup failed.', 'stage' => $stage];
    if ($error instanceof PDOException) {
        $diagnostic['sqlstate'] = preg_match('/^[A-Z0-9]{5}$/D', (string) $error->getCode())
            ? (string) $error->getCode() : 'unknown';
        $driverCode = $error->errorInfo[1] ?? null;
        if (is_int($driverCode)) {
            $diagnostic['driver_code'] = $driverCode;
        }
    }
    fwrite(STDERR, json_encode($diagnostic, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
