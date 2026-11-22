<?php
declare(strict_types=1);

function startKayasureSession(): bool
{
    return session_start([
        'cookie_httponly' => true,
        'cookie_secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'use_only_cookies' => true,
    ]);
}

function currentKayasureUser(AuthStore $store): ?array
{
    if (!isset($_SESSION['auth_user_id'], $_SESSION['auth_login_at'])) return null;
    $user = (int) $_SESSION['auth_login_at'] + 43200 > time()
        ? $store->user((int) $_SESSION['auth_user_id']) : null;
    if ($user === null) unset($_SESSION['auth_user_id'], $_SESSION['auth_login_at']);
    return $user;
}
