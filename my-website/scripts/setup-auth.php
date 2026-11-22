<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Create missing tables without changing existing database credentials or records.
require __DIR__ . '/setup-database.php';

$stream = null;
try {
    $workspace = realpath(dirname(__DIR__));
    $path = dirname(__DIR__) . '/.env.local';
    if ($workspace === false || is_link($path) || !is_file($path)
        || realpath($path) !== $workspace . DIRECTORY_SEPARATOR . '.env.local') {
        throw new RuntimeException('Invalid local configuration target.');
    }
    $stream = fopen($path, 'r+b');
    if ($stream === false || !flock($stream, LOCK_EX)) throw new RuntimeException('Configuration is unavailable.');
    $original = stream_get_contents($stream);
    $config = $original === false ? false : parse_ini_string($original, false, INI_SCANNER_RAW);
    if ($config === false) throw new RuntimeException('Configuration could not be loaded.');
    $key = configValue($config, 'AUTH_APP_KEY');
    $updated = $original;
    if ($key === '') {
        $setting = 'AUTH_APP_KEY="' . bin2hex(random_bytes(32)) . '"';
        if (preg_match('/(?m)^[\t ]*AUTH_APP_KEY[\t ]*=/', $original)) {
            $updated = preg_replace('/(?m)^[\t ]*AUTH_APP_KEY[\t ]*=[^\r\n]*/', $setting, $original);
        } else {
            $updated = $original . (str_ends_with($original, "\n") ? '' : PHP_EOL) . $setting . PHP_EOL;
        }
    } elseif (strlen($key) < 32) {
        throw new RuntimeException('AUTH_APP_KEY must contain at least 32 random characters.');
    }
    if (!is_string($updated)) throw new RuntimeException('Configuration could not be prepared.');
    if ($updated !== $original) {
        rewind($stream);
        $offset = 0;
        while ($offset < strlen($updated)) {
            $written = fwrite($stream, substr($updated, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Configuration write failed.');
            $offset += $written;
        }
        if (!ftruncate($stream, strlen($updated)) || !fflush($stream)) throw new RuntimeException('Configuration write failed.');
    }
    require_once $workspace . '/includes/AccountFolders.php';
    (new AccountFolders(configValue($config, 'AUTH_ACCOUNTS_PATH') ?: null))->initialize();
    echo 'Account tables, application key, and protected account storage are ready.' . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, 'Account setup failed. Check local configuration and file permissions.' . PHP_EOL);
    exit(1);
} finally {
    if (is_resource($stream)) {
        flock($stream, LOCK_UN);
        fclose($stream);
    }
}
