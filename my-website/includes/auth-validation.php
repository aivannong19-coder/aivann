<?php
declare(strict_types=1);

function normalizeAuthUsername(string $username): ?string
{
    $username = strtolower(trim($username));
    return preg_match('/^[a-z0-9][a-z0-9._-]{2,31}$/D', $username) ? $username : null;
}

function authPasswordError(string $password, string $confirm): ?string
{
    if (str_contains($password, "\0") || !mb_check_encoding($password, 'UTF-8')
        || mb_strlen($password, 'UTF-8') < 12 || strlen($password) > 72) {
        return 'Use at least 12 characters and no more than 72 bytes for your password.';
    }
    if ($password !== $confirm) return 'Your passwords do not match.';
    return null;
}
