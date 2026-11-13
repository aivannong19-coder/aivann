<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/ChatStore.php';

function checkStorage(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException($name);
    }
    echo 'PASS ' . $name . PHP_EOL;
}

$admin = null;
$hashA = hash('sha256', 'storage-test-a-' . bin2hex(random_bytes(24)));
$hashB = hash('sha256', 'storage-test-b-' . bin2hex(random_bytes(24)));
$passed = false;
try {
    $config = loadLocalConfig();
    $store = ChatStore::connect($config);
    // Administrative access is only for removing this test's own randomly named fixtures.
    $name = configValue($config, 'DB_NAME', 'kayasure_chat');
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
        throw new RuntimeException('Invalid test database configuration');
    }
    $admin = new PDO(
        'mysql:host=' . configValue($config, 'DB_HOST', '127.0.0.1')
        . ';port=' . configValue($config, 'DB_PORT', '3306') . ';dbname=' . $name . ';charset=utf8mb4',
        getenv('DB_SETUP_USER') !== false ? getenv('DB_SETUP_USER') : 'root',
        getenv('DB_SETUP_PASSWORD') !== false ? getenv('DB_SETUP_PASSWORD') : '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );

    $content = "I'm comparing cover — pamilya 👨‍👩‍👧 <script>literal text</script>";
    $questionId = $store->recordUserMessage($hashA, $content);
    $history = $store->history($hashA)['messages'];
    checkStorage(count($history) === 1 && $history[0]['content'] === $content && $history[0]['status'] === 'pending', 'Unicode, apostrophes, and literal markup persist unchanged');
    checkStorage($store->context($hashA) === [], 'Pending questions are excluded from model context');

    $blocked = false;
    try {
        $store->saveReply($hashB, $questionId, 'Must not be inserted');
    } catch (RuntimeException $expected) {
        $blocked = true;
    }
    checkStorage($blocked && count($store->history($hashA)['messages']) === 1, 'Another session cannot complete or read this conversation');
    checkStorage($store->history($hashB)['messages'] === [], 'Saved history is isolated by session');

    $store->saveReply($hashA, $questionId, 'Saved assistant reply ✅');
    $history = $store->history($hashA)['messages'];
    checkStorage(count($history) === 2 && $history[0]['status'] === 'completed' && $history[1]['role'] === 'assistant', 'Question completion and assistant reply are committed together');
    checkStorage($store->context($hashA) === [
        ['role' => 'user', 'content' => $content],
        ['role' => 'assistant', 'content' => 'Saved assistant reply ✅'],
    ], 'Model context uses saved completed exchanges in order');

    $duplicateBlocked = false;
    try {
        $store->saveReply($hashA, $questionId, 'Duplicate reply');
    } catch (RuntimeException $expected) {
        $duplicateBlocked = true;
    }
    checkStorage($duplicateBlocked && count($store->history($hashA)['messages']) === 2, 'Duplicate completion rolls back without a second reply');

    $failedId = $store->recordUserMessage($hashA, 'A question whose API request failed');
    $store->failMessage($hashA, $failedId, 'The assistant is temporarily unavailable.');
    $history = $store->history($hashA)['messages'];
    checkStorage(count($history) === 3 && $history[2]['status'] === 'failed'
        && $history[2]['error_message'] === 'The assistant is temporarily unavailable.'
        && count($store->context($hashA)) === 2, 'Failed requests remain saved and do not pollute model context');

    $reconnected = ChatStore::connect($config);
    checkStorage($reconnected->history($hashA)['messages'] === $history, 'Messages survive a fresh database connection');
    $passed = true;
} catch (Throwable $error) {
    // Do not echo exception text: database exceptions may contain connection details.
    fwrite(STDERR, 'Storage verification failed (' . get_class($error) . ').' . PHP_EOL);
} finally {
    if ($admin instanceof PDO) {
        try {
            $cleanup = $admin->prepare('DELETE FROM chat_conversations WHERE session_hash IN (?, ?)');
            $cleanup->execute([$hashA, $hashB]);
            echo 'Test fixtures removed.' . PHP_EOL;
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'Test fixture cleanup failed.' . PHP_EOL);
            $passed = false;
        }
    }
}
exit($passed ? 0 : 1);
