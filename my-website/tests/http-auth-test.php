<?php
declare(strict_types=1);

// The isolated router exposes the real production handler and no fixture endpoints.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (!in_array($path, ['/auth.php', '/dashboard.php'], true)) {
        http_response_code(404);
        exit;
    }
    require dirname(__DIR__) . $path;
    return;
}
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/config.php';

function checkHttpAuth(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException($description);
    echo 'PASS ' . $description . PHP_EOL;
}

function requestAuth(CurlHandle $client, string $url, string $method = 'GET',
    array|string|null $body = null, ?string $csrf = null, array $extraHeaders = []): array
{
    $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $extraHeaders);
    if ($csrf !== null) $headers[] = 'X-CSRF-Token: ' . $csrf;
    curl_setopt_array($client, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => '',
        CURLOPT_NOBODY => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => is_array($body) ? json_encode($body) : $body,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    $responseHeaders = [];
    curl_setopt($client, CURLOPT_HEADERFUNCTION, static function ($handle, string $line) use (&$responseHeaders): int {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))][] = trim($parts[1]);
        return strlen($line);
    });
    $response = curl_exec($client);
    return [(int) curl_getinfo($client, CURLINFO_HTTP_CODE),
        is_string($response) ? json_decode($response, true) : null, $responseHeaders];
}

function requestDashboard(CurlHandle $client, string $url, string $method = 'GET'): array
{
    curl_setopt_array($client, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => '',
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
        CURLOPT_POSTFIELDS => null,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_NOBODY => $method === 'HEAD',
    ]);
    $headers = [];
    curl_setopt($client, CURLOPT_HEADERFUNCTION, static function ($handle, string $line) use (&$headers): int {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) $headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
        return strlen($line);
    });
    $body = curl_exec($client);
    return [(int) curl_getinfo($client, CURLINFO_HTTP_CODE), is_string($body) ? $body : '', $headers];
}

function authTestSession(CurlHandle $client): ?string
{
    foreach (curl_getinfo($client, CURLINFO_COOKIELIST) as $cookie) {
        $parts = explode("\t", $cookie);
        if (count($parts) === 7 && $parts[5] === 'PHPSESSID') return $parts[6];
    }
    return null;
}

function expireAuthTestSession(CurlHandle $client, string $runtime): void
{
    $id = authTestSession($client);
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $id)) {
        throw new RuntimeException('Missing isolated test session');
    }
    $path = $runtime . '/sess_' . $id;
    if (is_link($path) || !is_file($path) || realpath(dirname($path)) !== realpath($runtime)) {
        throw new RuntimeException('Unsafe test session fixture');
    }
    $original = file_get_contents($path);
    if (!is_string($original)) throw new RuntimeException('Unreadable test session');
    $expired = preg_replace('/auth_login_at\|i:[0-9]+;/',
        'auth_login_at|i:' . (time() - 86400) . ';', $original, 1, $count);
    if ($count !== 1 || !is_string($expired) || file_put_contents($path, $expired, LOCK_EX) !== strlen($expired)) {
        throw new RuntimeException('Test session expiration failed');
    }
}

function cleanHttpAuthRuntime(string $path): void
{
    if (!is_dir($path)) return;
    $resolved = realpath($path);
    $tests = realpath(__DIR__);
    if ($resolved === false || $tests === false || is_link($path)
        || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $tests) . '/runtime-')) {
        throw new RuntimeException('Unsafe test cleanup path');
    }
    $accounts = $resolved . '/accounts';
    if (is_dir($accounts)) {
        foreach (new DirectoryIterator($accounts) as $entry) {
            if ($entry->isDot()) continue;
            if ($entry->isLink()) throw new RuntimeException('Unexpected test symbolic link');
            if ($entry->isDir() && preg_match('/^[a-f0-9]{32}$/D', $entry->getFilename())) {
                if (!rmdir($entry->getPathname())) throw new RuntimeException('Test account folder is not empty');
            } elseif ($entry->isFile() && $entry->getFilename() === '.htaccess') {
                unlink($entry->getPathname());
            } else throw new RuntimeException('Unexpected test account content');
        }
        rmdir($accounts);
    }
    foreach (new DirectoryIterator($resolved) as $entry) {
        if ($entry->isDot()) continue;
        if ($entry->isLink() || !$entry->isFile()
            || (!str_starts_with($entry->getFilename(), 'sess_')
                && !in_array($entry->getFilename(), ['stdout.log', 'stderr.log'], true))) {
            throw new RuntimeException('Unexpected HTTP auth test content');
        }
        unlink($entry->getPathname());
    }
    rmdir($resolved);
}

$server = null;
$pipes = [];
$clientA = curl_init();
$clientB = curl_init();
$clientC = curl_init();
$runtime = __DIR__ . '/runtime-' . bin2hex(random_bytes(8));
$accountsRoot = $runtime . '/accounts';
$admin = null;
$passed = false;
$stage = 'configuration';
$tag = bin2hex(random_bytes(8));
$fixtureKey = bin2hex(random_bytes(32));
$username = 'http_auth_' . $tag;
$unknown = 'unknown_' . $tag;
$limited = 'blocked_' . $tag;
$password = 'A secure HTTP test password ' . $tag;
$identifiers = [$username, $unknown, $limited, "' or 1=1 --"];
try {
    $config = loadLocalConfig();
    $databaseName = configValue($config, 'DB_NAME', 'kayasure_chat');
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $databaseName)) throw new RuntimeException('Invalid test database');
    $admin = new PDO(
        'mysql:host=' . configValue($config, 'DB_HOST', '127.0.0.1')
        . ';port=' . configValue($config, 'DB_PORT', '3306') . ';dbname=' . $databaseName . ';charset=utf8mb4',
        getenv('DB_SETUP_USER') !== false ? getenv('DB_SETUP_USER') : 'root',
        getenv('DB_SETUP_PASSWORD') !== false ? getenv('DB_SETUP_PASSWORD') : '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    mkdir($runtime, 0700);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    if ($socket === false) throw new RuntimeException('Test port unavailable');
    $port = substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $environment = getenv();
    $environment['AUTH_APP_KEY'] = $fixtureKey;
    $environment['AUTH_ACCOUNTS_PATH'] = $accountsRoot;
    $server = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $runtime, '-d', 'session.name=PHPSESSID',
            '-d', 'session.serialize_handler=php',
            '-S', '127.0.0.1:' . $port, '-t', __DIR__, __FILE__],
        [['pipe', 'r'], ['file', $runtime . '/stdout.log', 'a'], ['file', $runtime . '/stderr.log', 'a']],
        $pipes, dirname(__DIR__), $environment, ['bypass_shell' => true]
    );
    if (!is_resource($server)) throw new RuntimeException('Test server unavailable');
    $url = 'http://127.0.0.1:' . $port . '/auth.php';
    $dashboardUrl = 'http://127.0.0.1:' . $port . '/dashboard.php';
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status, $state, $headers] = requestAuth($clientA, $url);
        if ($status !== 0) break;
        usleep(50000);
    }
    $stage = 'initial state';
    checkHttpAuth($status === 200 && array_key_exists('user', $state) && $state['user'] === null
        && ($state['registrationAvailable'] ?? false) === true
        && preg_match('/^[a-f0-9]{64}$/D', $state['csrfToken'] ?? '') === 1,
        'Fresh sessions expose an available username registration form and a CSRF token');
    $csrfA = $state['csrfToken'];
    $cookie = implode('; ', $headers['set-cookie'] ?? []);
    checkHttpAuth(stripos($cookie, 'HttpOnly') !== false && stripos($cookie, 'SameSite=Lax') !== false
        && str_contains(implode(', ', $headers['cache-control'] ?? []), 'no-store'),
        'Authentication uses HttpOnly SameSite cookies and disables response caching');

    $stage = 'guest dashboard authorization';
    [$status, $page, $headers] = requestDashboard($clientA, $dashboardUrl);
    checkHttpAuth($status === 303 && ($headers['location'][0] ?? '') === './?login=1'
        && !str_contains($page, 'Dashboard') && !str_contains($page, $username),
        'Guest dashboard requests redirect to login without exposing account content');
    [$status, , $headers] = requestDashboard($clientA, $dashboardUrl, 'POST');
    checkHttpAuth($status === 405 && ($headers['allow'][0] ?? '') === 'GET, HEAD',
        'The dashboard accepts only GET and HEAD requests');

    $stage = 'request validation';
    [$status] = requestAuth($clientA, $url, 'POST', ['action' => 'logout']);
    checkHttpAuth($status === 403, 'State changes reject a missing CSRF token');
    [$status] = requestAuth($clientA, $url, 'POST', '{invalid', $csrfA);
    checkHttpAuth($status === 400, 'Malformed JSON is rejected');
    [$status] = requestAuth($clientA, $url, 'POST', str_repeat('x', 8001), $csrfA);
    checkHttpAuth($status === 413, 'Oversized authentication requests are rejected');
    [$status] = requestAuth($clientA, $url, 'DELETE');
    checkHttpAuth($status === 405, 'Unsupported HTTP methods are rejected');
    [$status] = requestAuth($clientA, $url, 'POST',
        ['action' => 'login', 'username' => [], 'password' => $password], $csrfA);
    checkHttpAuth($status === 400, 'Non-text login credentials are rejected');
    foreach (['signup-phone', 'google-start', 'verify-otp', 'resend-otp'] as $action) {
        [$status] = requestAuth($clientA, $url, 'POST', ['action' => $action], $csrfA);
        if ($status !== 400) throw new RuntimeException('Removed sign-up action accepted');
    }
    [$status] = requestAuth($clientA, $url . '?action=google-callback&state=invalid&code=synthetic');
    checkHttpAuth($status === 400, 'Removed phone, Google, and OTP routes cannot start authentication');

    $stage = 'registration validation';
    foreach (['../escape', 'bad username'] as $badName) {
        [$status] = requestAuth($clientA, $url, 'POST',
            ['action' => 'create-account', 'username' => $badName,
                'password' => $password, 'passwordConfirm' => $password], $csrfA);
        if ($status !== 400) throw new RuntimeException('Invalid username accepted');
    }
    checkHttpAuth(true, 'Registration rejects traversal paths and invalid username characters');
    [$status] = requestAuth($clientA, $url, 'POST',
        ['action' => 'create-account', 'username' => $username,
            'password' => 'short', 'passwordConfirm' => 'short'], $csrfA);
    checkHttpAuth($status === 400, 'Registration rejects short passwords');
    [$status] = requestAuth($clientA, $url, 'POST',
        ['action' => 'create-account', 'username' => $username,
            'password' => $password, 'passwordConfirm' => $password . 'different'], $csrfA);
    checkHttpAuth($status === 400, 'Registration requires matching password confirmation');

    $stage = 'registration and private folder';
    $beforeSession = authTestSession($clientA);
    [$status, $state] = requestAuth($clientA, $url, 'POST',
        ['action' => 'create-account', 'username' => '  ' . strtoupper($username) . '  ',
            'password' => $password, 'passwordConfirm' => $password], $csrfA);
    checkHttpAuth($status === 201 && ($state['user']['username'] ?? null) === $username
        && !array_key_exists('password_hash', $state['user'])
        && !array_key_exists('storage_key', $state['user']),
        'Username registration creates the account and signs it in without exposing secrets');
    $userId = $state['user']['id'];
    checkHttpAuth(authTestSession($clientA) !== $beforeSession && $state['csrfToken'] !== $csrfA,
        'Registration rotates the session ID and CSRF token');
    $csrfA = $state['csrfToken'];
    $readAccount = $admin->prepare('SELECT storage_key, password_hash FROM users WHERE id = ?');
    $readAccount->execute([$userId]);
    $row = $readAccount->fetch(PDO::FETCH_ASSOC);
    checkHttpAuth(preg_match('/^[a-f0-9]{32}$/D', $row['storage_key'] ?? '') === 1
        && is_dir($accountsRoot . '/' . $row['storage_key'])
        && !is_dir($accountsRoot . '/' . $username)
        && $row['password_hash'] !== $password && password_verify($password, $row['password_hash']),
        'Registration creates a private opaque account folder and stores a password hash');

    $stage = 'registered dashboard authorization';
    [$status, $page, $headers] = requestDashboard($clientA, $dashboardUrl);
    checkHttpAuth($status === 200 && str_contains($page, 'Dashboard')
        && str_contains($page, htmlspecialchars($username, ENT_QUOTES, 'UTF-8'))
        && !str_contains($page, $password) && !str_contains($page, $row['password_hash'])
        && !str_contains($page, $row['storage_key'])
        && !str_contains($page, 'You’re signed in') && !str_contains($page, "You're signed in"),
        'Registered sessions receive their dashboard without a sign-in confirmation or account secrets');
    checkHttpAuth(str_contains(implode(', ', $headers['cache-control'] ?? []), 'no-store'),
        'Private dashboard pages disable browser and intermediary caching');
    [$status, $page] = requestDashboard($clientA, $dashboardUrl, 'HEAD');
    checkHttpAuth($status === 200 && $page === '', 'Authenticated HEAD requests expose no dashboard body');

    $stage = 'browser isolation and password login';
    [$status, $stateB] = requestAuth($clientB, $url);
    checkHttpAuth($status === 200 && $stateB['user'] === null,
        'A new browser remains logged out after another browser registers');
    [$status, $page, $headers] = requestDashboard($clientB, $dashboardUrl);
    checkHttpAuth($status === 303 && ($headers['location'][0] ?? '') === './?login=1'
        && !str_contains($page, $username),
        'An unrelated browser cannot open another browser account dashboard');
    $csrfB = $stateB['csrfToken'];
    [$status] = requestAuth($clientB, $url, 'POST', ['action' => 'logout'], $csrfA);
    checkHttpAuth($status === 403, 'Another browser cannot use the first browser CSRF token');
    [$status, $badExisting] = requestAuth($clientB, $url, 'POST',
        ['action' => 'login', 'username' => $username, 'password' => 'incorrect password'], $csrfB);
    [$unknownStatus, $badUnknown] = requestAuth($clientB, $url, 'POST',
        ['action' => 'login', 'username' => $unknown, 'password' => 'incorrect password'], $csrfB);
    checkHttpAuth($status === 401 && $unknownStatus === 401 && $badExisting['error'] === $badUnknown['error'],
        'Incorrect passwords and unknown usernames return the same safe error');
    [$status] = requestAuth($clientB, $url, 'POST',
        ['action' => 'login', 'username' => "' or 1=1 --", 'password' => $password], $csrfB);
    checkHttpAuth($status === 401, 'SQL syntax cannot bypass username and password login');
    $beforeSession = authTestSession($clientB);
    [$status, $stateB] = requestAuth($clientB, $url, 'POST',
        ['action' => 'login', 'username' => ' ' . strtoupper($username) . ' ', 'password' => $password], $csrfB);
    checkHttpAuth($status === 200 && ($stateB['user']['id'] ?? null) === $userId
        && authTestSession($clientB) !== $beforeSession && $stateB['csrfToken'] !== $csrfB,
        'A new browser can log in using the normalized username and password with session rotation');
    $csrfB = $stateB['csrfToken'];
    [$status, $page] = requestDashboard($clientB, $dashboardUrl);
    checkHttpAuth($status === 200 && str_contains($page, htmlspecialchars($username, ENT_QUOTES, 'UTF-8')),
        'Password login grants the new browser access to its dashboard');

    $stage = 'logout and duplicate registration';
    $beforeSession = authTestSession($clientA);
    $oldCsrfA = $csrfA;
    [$status, $state] = requestAuth($clientA, $url, 'POST', ['action' => 'logout'], $csrfA);
    checkHttpAuth($status === 200 && $state['user'] === null
        && authTestSession($clientA) !== $beforeSession && $state['csrfToken'] !== $oldCsrfA,
        'Logout clears the user and rotates the session and CSRF token');
    $csrfA = $state['csrfToken'];
    [$status, $page, $headers] = requestDashboard($clientA, $dashboardUrl);
    checkHttpAuth($status === 303 && ($headers['location'][0] ?? '') === './?login=1'
        && !str_contains($page, $username),
        'Logging out immediately prevents access to the private dashboard');
    [$status] = requestAuth($clientA, $url, 'POST', ['action' => 'logout'], $oldCsrfA);
    checkHttpAuth($status === 403, 'A stale token cannot act on a rotated session');
    [$status, $stateB] = requestAuth($clientB, $url);
    checkHttpAuth($status === 200 && ($stateB['user']['id'] ?? null) === $userId,
        'Logging out one browser leaves the other browser session independent');
    [$status, $state] = requestAuth($clientA, $url, 'POST',
        ['action' => 'create-account', 'username' => strtoupper($username),
            'password' => $password, 'passwordConfirm' => $password], $csrfA);
    checkHttpAuth($status === 409 && $state['user'] === null && is_string($state['error'] ?? null),
        'Duplicate usernames with different casing return a safe conflict');
    $folderNames = array_values(array_filter(scandir($accountsRoot),
        static fn(string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($accountsRoot . '/' . $entry)));
    checkHttpAuth(count($folderNames) === 1, 'Rejected registrations do not leave extra private account folders');
    $stage = 'expired dashboard authorization';
    expireAuthTestSession($clientB, $runtime);
    [$status, $page, $headers] = requestDashboard($clientB, $dashboardUrl);
    checkHttpAuth($status === 303 && ($headers['location'][0] ?? '') === './?login=1'
        && !str_contains($page, $username),
        'Expired login sessions cannot retrieve the private dashboard');
    [$status, $stateB] = requestAuth($clientB, $url);
    if ($status !== 200 || $stateB['user'] !== null) throw new RuntimeException('Expired identity was not cleared');
    $csrfB = $stateB['csrfToken'];
    [$status, $stateB] = requestAuth($clientB, $url, 'POST', ['action' => 'logout'], $csrfB);
    if ($status !== 200) throw new RuntimeException('Second browser logout failed');
    $csrfB = $stateB['csrfToken'];

    $stage = 'persistent login throttling';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        [$status] = requestAuth($clientB, $url, 'POST',
            ['action' => 'login', 'username' => $limited, 'password' => 'incorrect password'], $csrfB);
        if ($status !== 401) throw new RuntimeException('Early login throttle');
    }
    [$status, $stateC] = requestAuth($clientC, $url);
    if ($status !== 200) throw new RuntimeException('Third browser state failed');
    [$status, , $headers] = requestAuth($clientC, $url, 'POST',
        ['action' => 'login', 'username' => $limited, 'password' => 'incorrect password'], $stateC['csrfToken']);
    checkHttpAuth($status === 429 && isset($headers['retry-after'][0]),
        'Repeated password attempts remain throttled across new browser sessions');
    $passed = true;
} catch (Throwable $error) {
    fwrite(STDERR, 'HTTP auth verification failed at ' . $stage . ' (' . get_class($error) . ').' . PHP_EOL);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($server);
    }
    if ($admin instanceof PDO) {
        try {
            $removeUser = $admin->prepare('DELETE FROM users WHERE username = ?');
            $removeUser->execute([$username]);
            $buckets = ['login-ip:127.0.0.1', 'signup-ip:127.0.0.1'];
            foreach ($identifiers as $identifier) $buckets[] = 'login-id:' . strtolower($identifier);
            $removeRate = $admin->prepare('DELETE FROM auth_rate_limits WHERE key_hash = ?');
            foreach ($buckets as $bucket) {
                $removeRate->execute([hash_hmac('sha256', 'rate:' . $bucket, $fixtureKey)]);
            }
        } catch (Throwable) {
            fwrite(STDERR, 'HTTP auth database cleanup failed.' . PHP_EOL);
            $passed = false;
        }
    }
    foreach ([$clientA, $clientB, $clientC] as $client) curl_close($client);
    try { cleanHttpAuthRuntime($runtime); } catch (Throwable) {
        fwrite(STDERR, 'HTTP auth folder cleanup failed.' . PHP_EOL);
        $passed = false;
    }
    echo 'Temporary auth server, accounts, and private test folders removed.' . PHP_EOL;
}
exit($passed ? 0 : 1);
