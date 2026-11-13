<?php
// This test doubles as a restricted router for its isolated PHP test server.
if (PHP_SAPI === 'cli-server') {
    if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/chat.php') {
        http_response_code(404);
        exit;
    }
    require dirname(__DIR__) . '/chat.php';
    return;
}
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/config.php';

function requestChat(CurlHandle $client, string $url, string $method = 'GET', ?string $message = null): array
{
    curl_setopt_array($client, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_COOKIEFILE => '',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $message === null ? null : json_encode(['message' => $message]),
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    $body = curl_exec($client);
    return [(int) curl_getinfo($client, CURLINFO_HTTP_CODE), is_string($body) ? json_decode($body, true) : null];
}

function sessionHashForTest(CurlHandle $client): ?string
{
    foreach (curl_getinfo($client, CURLINFO_COOKIELIST) as $cookie) {
        $parts = explode("\t", $cookie);
        if (count($parts) === 7 && $parts[5] === 'PHPSESSID') {
            return hash('sha256', $parts[6]);
        }
    }
    return null;
}

$server = null;
$pipes = [];
$clientA = curl_init();
$clientB = curl_init();
$runtime = __DIR__ . '/runtime-' . bin2hex(random_bytes(8));
$admin = null;
$passed = false;
try {
    $config = loadLocalConfig();
    $databaseName = configValue($config, 'DB_NAME', 'kayasure_chat');
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $databaseName)) {
        throw new RuntimeException('Invalid test configuration');
    }
    $admin = new PDO(
        'mysql:host=' . configValue($config, 'DB_HOST', '127.0.0.1')
        . ';port=' . configValue($config, 'DB_PORT', '3306') . ';dbname=' . $databaseName . ';charset=utf8mb4',
        getenv('DB_SETUP_USER') !== false ? getenv('DB_SETUP_USER') : 'root',
        getenv('DB_SETUP_PASSWORD') !== false ? getenv('DB_SETUP_PASSWORD') : '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    mkdir($runtime, 0700);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    if ($socket === false) {
        throw new RuntimeException('Test port unavailable');
    }
    $port = substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $environment = getenv();
    // The invalid synthetic credential guarantees this test never calls OpenAI.
    $environment['OPENAI_API_KEY'] = 'key_storage_test_not_a_secret';
    $server = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $runtime, '-d', 'session.name=PHPSESSID',
            '-S', '127.0.0.1:' . $port, '-t', __DIR__, __FILE__],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        $environment,
        ['bypass_shell' => true]
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Test server unavailable');
    }
    $url = 'http://127.0.0.1:' . $port . '/chat.php';
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status, $history] = requestChat($clientA, $url);
        if ($status === 200) {
            break;
        }
        usleep(50000);
    }
    if ($status !== 200 || ($history['messages'] ?? null) !== []) {
        throw new RuntimeException('Fresh session history failed');
    }
    echo 'PASS New HTTP session has empty saved history.' . PHP_EOL;

    $question = "HTTP database test — family's plans 🏡";
    [$status, $response] = requestChat($clientA, $url, 'POST', $question);
    if ($status !== 503 || !str_contains($response['error'] ?? '', 'API key ID')) {
        throw new RuntimeException('Synthetic API failure was not handled');
    }
    [$status, $history] = requestChat($clientA, $url);
    $rows = $history['messages'] ?? [];
    if ($status !== 200 || count($rows) !== 1 || $rows[0]['content'] !== $question
        || $rows[0]['status'] !== 'failed' || $rows[0]['error_message'] !== $response['error']) {
        throw new RuntimeException('HTTP persistence failed');
    }
    echo 'PASS POST saves the question and safe API error; GET restores them.' . PHP_EOL;

    [$status, $otherHistory] = requestChat($clientB, $url);
    if ($status !== 200 || ($otherHistory['messages'] ?? null) !== []) {
        throw new RuntimeException('HTTP session isolation failed');
    }
    echo 'PASS Another HTTP session cannot see the saved conversation.' . PHP_EOL;
    echo 'PASS No live OpenAI request was needed.' . PHP_EOL;
    $passed = true;
} catch (Throwable $error) {
    fwrite(STDERR, 'HTTP storage verification failed (' . get_class($error) . ').' . PHP_EOL);
} finally {
    if ($admin instanceof PDO) {
        foreach ([$clientA, $clientB] as $client) {
            $hash = sessionHashForTest($client);
            if ($hash !== null) {
                try {
                    $cleanup = $admin->prepare('DELETE FROM chat_conversations WHERE session_hash = ?');
                    $cleanup->execute([$hash]);
                } catch (Throwable) {
                    $passed = false;
                }
            }
        }
    }
    if (is_resource($server)) {
        proc_terminate($server);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($server);
    }
    curl_close($clientA);
    curl_close($clientB);
    if (is_dir($runtime)) {
        foreach (glob($runtime . '/sess_*') as $sessionFile) {
            unlink($sessionFile);
        }
        rmdir($runtime);
    }
    echo 'Temporary test server and fixtures removed.' . PHP_EOL;
}
exit($passed ? 0 : 1);
