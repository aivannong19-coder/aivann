<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/AuthStore.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

try {
    if (!startKayasureSession()) throw new RuntimeException('The session could not be started.');
    $store = AuthStore::connect(loadLocalConfig());
    $dashboardUser = currentKayasureUser($store);
    if ($dashboardUser === null) {
        session_write_close();
        header('Location: ./?login=1', true, 303);
        exit;
    }
    if (!isset($_SESSION['auth_csrf'])) $_SESSION['auth_csrf'] = bin2hex(random_bytes(32));
    $dashboardCsrf = $_SESSION['auth_csrf'];
    $createdAt = $store->createdAt($dashboardUser['id']);
    $dashboardCreatedAt = $createdAt === null ? 'Unavailable' : (new DateTimeImmutable($createdAt, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Asia/Manila'))->format('F j, Y');
    session_write_close();
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
    require __DIR__ . '/includes/dashboard-view.php';
} catch (Throwable $error) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    error_log('Dashboard request failed (' . get_class($error) . ')');
    http_response_code(503);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Kayasure dashboard</title><body><h1>Your dashboard is temporarily unavailable.</h1>'
        . '<p>Please try again shortly.</p><a href="./">Return to Kayasure</a></body></html>';
}
