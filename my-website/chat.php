<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/ChatStore.php';
require_once __DIR__ . '/includes/session.php';

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    respond(405, ['error' => 'Method not allowed']);
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input', false, null, 0, 20001);
    if ($rawBody === false || strlen($rawBody) > 20000) {
        respond(413, ['error' => 'Message is too large']);
    }

    $data = json_decode($rawBody, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        respond(400, ['error' => 'Send a valid JSON object with a message']);
    }

    if (!isset($data['message']) || !is_string($data['message'])) {
        respond(400, ['error' => 'Message must be text']);
    }

    $message = trim($data['message']);
    if ($message === '' || preg_match_all('/./us', $message) > 4000) {
        respond(400, ['error' => 'Enter a message between 1 and 4000 characters']);
    }
}

try {
    $localConfig = loadLocalConfig();
} catch (Throwable $error) {
    respond(500, ['error' => 'The server configuration could not be loaded']);
}

if (!startKayasureSession()) {
    respond(500, ['error' => 'The chat session could not be started']);
}

// The browser cannot choose a conversation ID or read another PHP session's history.
$sessionHash = hash('sha256', session_id());
try {
    $store = ChatStore::connect($localConfig);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(200, $store->history($sessionHash));
    }
    $userMessageId = $store->recordUserMessage($sessionHash, $message);
} catch (Throwable $error) {
    error_log('Conversation storage failed (' . get_class($error) . ')');
    respond(503, ['error' => 'Conversation storage is unavailable. Please try again later.']);
}

$fail = function (int $status, string $safeError) use ($store, $sessionHash, $userMessageId): never {
    try {
        $store->failMessage($sessionHash, $userMessageId, $safeError);
    } catch (Throwable $error) {
        error_log('Could not record conversation failure (' . get_class($error) . ')');
    }
    respond($status, ['error' => $safeError]);
};

try {
    $apiKey = trim(configValue($localConfig, 'OPENAI_API_KEY'));
    $model = trim(configValue($localConfig, 'OPENAI_MODEL', 'gpt-6-luna'));
} catch (Throwable $error) {
    $fail(500, 'The server API configuration could not be loaded');
}

if ($apiKey === '') {
    $fail(503, 'OpenAI is not configured yet. Complete the server API key setup.');
}

if (str_starts_with($apiKey, 'key_')) {
    $fail(503, 'OPENAI_API_KEY contains an API key ID. Replace it with the full secret API key in .env.local.');
}

if (!preg_match('/^sk-[A-Za-z0-9_-]+$/D', $apiKey)) {
    $fail(503, 'OPENAI_API_KEY must contain the complete OpenAI secret key. Check the value in .env.local.');
}

if (!function_exists('curl_init')) {
    $fail(500, 'Enable the PHP cURL extension to connect to OpenAI');
}

try {
    $history = $store->context($sessionHash);
} catch (Throwable $error) {
    error_log('Could not load model context (' . get_class($error) . ')');
    $fail(503, 'Your question was saved, but conversation history is temporarily unavailable.');
}

$input = array_merge(
    $history,
    [['role' => 'user', 'content' => $message]]
);

$payload = [
    'model' => $model,
    'instructions' => "You are Kaya, the AI guide for Kayasure's CTPL motor insurance website in the Philippines. "
        . 'Focus on Compulsory Third Party Liability (CTPL), general registration questions, and preparing enquiries. '
        . 'Explain eligible third-party death and bodily injury cover only in general terms, subject to policy terms, limits, and exclusions. '
        . 'Do not imply CTPL covers repairs to the insured vehicle or third-party property damage. '
        . 'Never invent premiums, benefit amounts, policy availability, accreditation, registration approval, or certificate issuance. '
        . 'This website can prepare an enquiry only; it cannot submit applications, take payment, issue quotes or policies, or verify certificates. '
        . 'Do not request sensitive identifiers or personal documents in chat. Refer policy-specific questions to the insurer. '
        . 'Politely redirect unrelated insurance questions to CTPL. Keep answers friendly, clear, and concise.',
    'input' => $input,
    'max_output_tokens' => 2000,
    'store' => false
];

$ch = curl_init('https://api.openai.com/v1/responses');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60
]);

$result = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErrorNumber = curl_errno($ch);
curl_close($ch);

$response = is_string($result) ? json_decode($result, true) : null;

if ($result === false || $status < 200 || $status >= 300) {
    // Never write API response bodies or credentials to the log.
    error_log('OpenAI request failed: HTTP ' . $status . ', cURL ' . $curlErrorNumber);

    if ($status === 401) {
        $fail(502, 'OpenAI rejected the API key. Replace OPENAI_API_KEY with an active secret API key.');
    }
    if ($status === 403) {
        $fail(502, 'OpenAI denied this request. Check project access and API key permissions.');
    }
    if ($status === 429) {
        $fail(503, 'OpenAI usage limit reached. Check project billing or try again shortly.');
    }
    if (($response['error']['code'] ?? '') === 'model_not_found') {
        $fail(502, 'The configured OpenAI model is unavailable for this project.');
    }

    $fail(502, 'Could not reach OpenAI. Please try again shortly.');
}

if (!is_array($response) || !empty($response['error'])) {
    $fail(502, 'OpenAI returned an invalid response. Please try again.');
}

// Extract the text from the Responses API output
$answer = '';

foreach (($response['output'] ?? []) as $item) {
    if (($item['type'] ?? '') !== 'message') {
        continue;
    }

    foreach (($item['content'] ?? []) as $content) {
        if (($content['type'] ?? '') === 'output_text') {
            $answer .= $content['text'] ?? '';
        } elseif (($content['type'] ?? '') === 'refusal') {
            $answer .= $content['refusal'] ?? '';
        }
    }
}

if (trim($answer) === '') {
    $fail(502, 'OpenAI returned no reply. Please try a shorter question.');
}

try {
    $replyId = $store->saveReply($sessionHash, $userMessageId, $answer);
} catch (Throwable $error) {
    error_log('Could not save assistant reply (' . get_class($error) . ')');
    $fail(503, 'Your question was saved, but the reply could not be saved. Please try again.');
}

respond(200, ['reply' => $answer, 'message_id' => $replyId]);
