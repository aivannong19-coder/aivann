<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/AuthStore.php';

final class AuthHttpException extends RuntimeException
{
    public function __construct(string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}

$store = null;

function authState(): array
{
    global $store;
    $user = $store instanceof AuthStore ? currentKayasureUser($store) : null;
    return ['csrfToken' => $_SESSION['auth_csrf'] ?? '', 'user' => $user,
        'registrationAvailable' => $store instanceof AuthStore];
}

function authRespond(int $status = 200, array $body = []): never
{
    http_response_code($status);
    try {
        $state = authState();
    } catch (Throwable) {
        $state = ['csrfToken' => $_SESSION['auth_csrf'] ?? '', 'user' => null, 'registrationAvailable' => false];
    }
    echo json_encode(array_merge($state, $body), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function authLimit(string $bucket, int $limit, int $seconds): void
{
    global $store;
    if (!$store->allow($bucket, $limit, $seconds)) {
        header('Retry-After: ' . $seconds);
        throw new AuthHttpException('Too many attempts. Please wait before trying again.', 429);
    }
}

function authIp(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function authLoginSession(int $userId): void
{
    if (!session_regenerate_id(true)) throw new RuntimeException('The login session could not be secured.');
    $_SESSION = ['auth_user_id' => $userId, 'auth_login_at' => time(), 'auth_csrf' => bin2hex(random_bytes(32))];
}

try {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        authRespond(405, ['error' => 'Method not allowed.']);
    }
    if (!startKayasureSession()) throw new RuntimeException('The account session could not be started.');
    if (!isset($_SESSION['auth_csrf'])) $_SESSION['auth_csrf'] = bin2hex(random_bytes(32));
    // Discard obsolete sign-up state from the previous provider-based flow.
    unset($_SESSION['auth_pending'], $_SESSION['auth_oauth'], $_SESSION['auth_flash']);
    $store = AuthStore::connect(loadLocalConfig());
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['action'])) throw new AuthHttpException('Unknown account action.');
        authRespond();
    }
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new AuthHttpException('Send an application/json request.', 415);
    }
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($csrf) || !hash_equals($_SESSION['auth_csrf'], $csrf)) {
        throw new AuthHttpException('Your session changed. Please try again.', 403);
    }
    $raw = file_get_contents('php://input', false, null, 0, 8001);
    if ($raw === false || strlen($raw) > 8000) throw new AuthHttpException('Request is too large.', 413);
    $data = json_decode($raw, true);
    if (!is_array($data) || !is_string($data['action'] ?? null)) throw new AuthHttpException('Send a valid account request.');
    $text = static function (string $name) use ($data): string {
        if (!is_string($data[$name] ?? null)) throw new AuthHttpException('Please complete all required fields.');
        return $data[$name];
    };
    switch ($data['action']) {
        case 'create-account':
            if (authState()['user'] !== null) throw new AuthHttpException('Log out before creating another account.');
            $username = normalizeAuthUsername($text('username'));
            if ($username === null) throw new AuthHttpException('Use 3 to 32 letters, numbers, dots, underscores, or hyphens. Start with a letter or number.');
            $password = $text('password');
            $error = authPasswordError($password, $text('passwordConfirm'));
            if ($error !== null) throw new AuthHttpException($error);
            authLimit('signup-ip:' . authIp(), 10, 3600);
            $user = $store->createUser($username, password_hash($password, PASSWORD_DEFAULT));
            authLoginSession($user['id']);
            authRespond(201, ['message' => 'Your account is saved. You are now logged in.']);

        case 'login':
            $rawUsername = $text('username');
            $password = $text('password');
            if (trim($rawUsername) === '' || strlen($rawUsername) > 128 || strlen($password) > 72 || str_contains($password, "\0")) {
                throw new AuthHttpException('Enter your username and password.');
            }
            $username = normalizeAuthUsername($rawUsername);
            authLimit('login-ip:' . authIp(), 30, 900);
            authLimit('login-id:' . ($username ?? strtolower(trim($rawUsername))), 10, 900);
            $record = $username === null ? null : $store->loginRecord($username);
            $hash = $record['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $valid = password_verify($password, $hash);
            if (!$valid || $record === null) throw new AuthHttpException('The username or password is incorrect.', 401);
            authLoginSession((int) $record['id']);
            authRespond(200, ['message' => 'Welcome back. You are now logged in.']);

        case 'logout':
            if (!session_regenerate_id(true)) throw new RuntimeException('The session could not be secured.');
            $_SESSION = ['auth_csrf' => bin2hex(random_bytes(32))];
            authRespond(200, ['message' => 'You have been logged out.']);

        default:
            throw new AuthHttpException('Unknown account action.');
    }
} catch (AuthHttpException $error) {
    authRespond($error->status, ['error' => $error->getMessage()]);
} catch (AuthConflictException $error) {
    authRespond(409, ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Account request failed (' . get_class($error) . ')');
    authRespond(503, ['error' => 'Account services are temporarily unavailable. Please try again later.']);
}
