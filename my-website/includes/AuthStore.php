<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-validation.php';
require_once __DIR__ . '/AccountFolders.php';

final class AuthConflictException extends RuntimeException
{
}

final class AuthStore
{
    private AccountFolders $folders;

    public function __construct(private PDO $database, private string $key, ?AccountFolders $folders = null)
    {
        if (strlen($key) < 32) throw new RuntimeException('Account security is not configured yet.');
        $this->folders = $folders ?? new AccountFolders();
    }

    public static function connect(array $config): self
    {
        $host = configValue($config, 'DB_HOST', '127.0.0.1');
        $port = configValue($config, 'DB_PORT', '3306');
        $name = configValue($config, 'DB_NAME', 'kayasure_chat');
        $user = configValue($config, 'DB_USER');
        if ($user === '' || !preg_match('/^[a-zA-Z0-9_]+$/D', $name)
            || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
            || preg_match('/[;\x00-\x20]/', $host)) {
            throw new RuntimeException('Account storage is not configured yet.');
        }
        $database = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4',
            $user, configValue($config, 'DB_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]
        );
        $database->exec("SET time_zone = '+00:00'");
        return new self($database, configValue($config, 'AUTH_APP_KEY'),
            new AccountFolders(configValue($config, 'AUTH_ACCOUNTS_PATH') ?: null));
    }

    public function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key);
    }

    // Atomic fixed-window limits persist across new cookies and browsers.
    public function allow(string $bucket, int $limit, int $seconds): bool
    {
        $now = time();
        $cutoff = $now - $seconds;
        $id = $this->fingerprint('rate:' . $bucket);
        $write = $this->database->prepare(
            'INSERT INTO auth_rate_limits (key_hash, window_started, hits) VALUES (?, ?, 1) '
            . 'ON DUPLICATE KEY UPDATE hits = IF(window_started <= ?, 1, hits + 1), '
            . 'window_started = IF(window_started <= ?, VALUES(window_started), window_started)'
        );
        $write->execute([$id, $now, $cutoff, $cutoff]);
        $read = $this->database->prepare('SELECT hits FROM auth_rate_limits WHERE key_hash = ?');
        $read->execute([$id]);
        return (int) $read->fetchColumn() <= $limit;
    }

    public function createUser(string $username, string $passwordHash): array
    {
        $username = normalizeAuthUsername($username);
        if ($username === null) throw new InvalidArgumentException('Invalid username.');
        if (password_get_info($passwordHash)['algo'] === null) throw new InvalidArgumentException('A password hash is required.');
        $this->folders->initialize();
        $storageKey = bin2hex(random_bytes(16));
        $folderCreated = false;
        $this->database->beginTransaction();
        try {
            $insert = $this->database->prepare(
                'INSERT INTO users (username, display_name, password_hash, storage_key) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$username, $username, $passwordHash, $storageKey]);
            $userId = (int) $this->database->lastInsertId();
            $this->folders->create($storageKey);
            $folderCreated = true;
            $this->database->commit();
            return ['id' => $userId, 'username' => $username, 'displayName' => $username];
        } catch (Throwable $error) {
            $rolledBack = false;
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
                $rolledBack = true;
            }
            if ($folderCreated && $rolledBack) $this->folders->removeEmpty($storageKey);
            if ($error instanceof PDOException && $error->getCode() === '23000') {
                throw new AuthConflictException('That username is already taken. Choose another or log in.');
            }
            throw $error;
        }
    }

    public function loginRecord(string $username): ?array
    {
        $username = normalizeAuthUsername($username);
        if ($username === null) return null;
        $query = $this->database->prepare('SELECT id, username, display_name, password_hash FROM users WHERE username = ? LIMIT 1');
        $query->execute([$username]);
        $user = $query->fetch();
        return $user === false ? null : $user;
    }

    public function user(int $id): ?array
    {
        $query = $this->database->prepare('SELECT id, username, display_name FROM users WHERE id = ? AND username IS NOT NULL');
        $query->execute([$id]);
        $user = $query->fetch();
        return $user === false ? null : [
            'id' => (int) $user['id'], 'username' => $user['username'], 'displayName' => $user['display_name'],
        ];
    }

    public function createdAt(int $id): ?string
    {
        $query = $this->database->prepare('SELECT created_at FROM users WHERE id = ? AND username IS NOT NULL');
        $query->execute([$id]);
        $created = $query->fetchColumn();
        return is_string($created) ? $created : null;
    }
}
