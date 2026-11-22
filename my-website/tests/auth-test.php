<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/AuthStore.php';
require_once dirname(__DIR__) . '/includes/AccountFolders.php';
require_once dirname(__DIR__) . '/includes/auth-validation.php';

function checkAuth(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException($description);
    echo 'PASS ' . $description . PHP_EOL;
}

function rejectsAuth(callable $operation, string $exception = RuntimeException::class): bool
{
    try { $operation(); } catch (Throwable $error) { return $error instanceof $exception; }
    return false;
}

function cleanAuthRuntime(string $path): void
{
    if (!is_dir($path)) return;
    $resolved = realpath($path);
    $tests = realpath(__DIR__);
    if ($resolved === false || $tests === false
        || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $tests) . '/runtime-')
        || is_link($path)) {
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
        throw new RuntimeException('Unexpected auth test content');
    }
    rmdir($resolved);
}

function allAuthValues(array $values, callable $predicate): bool
{
    foreach ($values as $value) if (!$predicate($value)) return false;
    return true;
}

$admin = null;
$users = [];
$rates = [];
$passed = false;
$originalKey = getenv('AUTH_APP_KEY');
$originalAccountsPath = getenv('AUTH_ACCOUNTS_PATH');
$fixtureKey = bin2hex(random_bytes(32));
$runtime = __DIR__ . '/runtime-' . bin2hex(random_bytes(8));
$accountsRoot = $runtime . '/accounts';
putenv('AUTH_APP_KEY=' . $fixtureKey);
putenv('AUTH_ACCOUNTS_PATH=' . $accountsRoot);
$stage = 'configuration';
try {
    $config = loadLocalConfig();
    $name = configValue($config, 'DB_NAME', 'kayasure_chat');
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) throw new RuntimeException('Invalid test database');
    $admin = new PDO(
        'mysql:host=' . configValue($config, 'DB_HOST', '127.0.0.1')
        . ';port=' . configValue($config, 'DB_PORT', '3306') . ';dbname=' . $name . ';charset=utf8mb4',
        getenv('DB_SETUP_USER') !== false ? getenv('DB_SETUP_USER') : 'root',
        getenv('DB_SETUP_PASSWORD') !== false ? getenv('DB_SETUP_PASSWORD') : '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    mkdir($runtime, 0700);
    $folders = new AccountFolders($accountsRoot);
    $folders->initialize();
    $store = AuthStore::connect($config);

    $stage = 'credential validation';
    checkAuth(normalizeAuthUsername('  Example.User-12  ') === 'example.user-12'
        && normalizeAuthUsername('a_1') === 'a_1'
        && normalizeAuthUsername(str_repeat('a', 32)) === str_repeat('a', 32),
        'Usernames normalize case and surrounding whitespace within the accepted length');
    $invalidNames = ['', 'ab', str_repeat('a', 33), '../escape', 'a/b', 'a\\b', '-username',
        'with space', '<script>', 'name@example.com', "name\0suffix", 'éclair'];
    checkAuth(allAuthValues($invalidNames, static fn(string $name): bool => normalizeAuthUsername($name) === null),
        'Invalid usernames reject traversal, markup, whitespace, nulls, and unsupported characters');
    checkAuth(authPasswordError('twelve chars!', 'twelve chars!') === null
        && authPasswordError(str_repeat('é', 12), str_repeat('é', 12)) === null
        && authPasswordError('short', 'short') !== null
        && authPasswordError(str_repeat('x', 73), str_repeat('x', 73)) !== null
        && authPasswordError(str_repeat('é', 37), str_repeat('é', 37)) !== null
        && authPasswordError("valid long\0password", "valid long\0password") !== null
        && authPasswordError("invalid\xc3\x28 password", "invalid\xc3\x28 password") !== null
        && authPasswordError('twelve chars!', 'not matching!') !== null,
        'Passwords enforce character length, UTF-8 validity, byte bounds, null rejection, and confirmation');

    $tag = bin2hex(random_bytes(8));
    $username = 'auth_test_' . $tag;
    $password = 'A long test password ' . $tag;
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $createUser = static function (string $name) use ($store, $passwordHash, &$users): array {
        $created = $store->createUser($name, $passwordHash);
        $users[] = $created['id'];
        return $created;
    };
    $stage = 'account creation';
    $user = $createUser('  ' . strtoupper($username) . '  ');
    checkAuth($user['username'] === $username && is_int($user['id'])
        && !array_key_exists('password_hash', $user) && !array_key_exists('storage_key', $user),
        'Account creation normalizes the username and returns only public account fields');
    $record = $store->loginRecord($username);
    checkAuth($record !== null && $record['password_hash'] !== $password
        && password_verify($password, $record['password_hash'])
        && !password_verify('incorrect password', $record['password_hash']),
        'Passwords persist as password hashes and reject an incorrect password');
    $readAccount = $admin->prepare('SELECT username, storage_key FROM users WHERE id = ?');
    $readAccount->execute([$user['id']]);
    $row = $readAccount->fetch(PDO::FETCH_ASSOC);
    checkAuth(preg_match('/^[a-f0-9]{32}$/D', $row['storage_key'] ?? '') === 1
        && is_dir($folders->path($row['storage_key']))
        && !is_dir($accountsRoot . '/' . $username),
        'Each account receives an opaque private folder independent of the username');
    checkAuth(is_file($accountsRoot . '/.htaccess')
        && str_contains((string) file_get_contents($accountsRoot . '/.htaccess'), 'Require all denied'),
        'The account storage root contains an Apache access-denial rule');
    $other = $createUser('auth_other_' . $tag);
    $readAccount->execute([$other['id']]);
    $otherRow = $readAccount->fetch(PDO::FETCH_ASSOC);
    checkAuth($otherRow['storage_key'] !== $row['storage_key']
        && is_dir($folders->path($otherRow['storage_key'])),
        'Different accounts receive separate private storage folders');
    checkAuth(AuthStore::connect($config)->user($user['id']) === $user,
        'A fresh database connection restores the registered account');

    $stage = 'duplicates and invalid credentials';
    checkAuth(rejectsAuth(fn() => $createUser(strtoupper($username)), AuthConflictException::class),
        'Case variants cannot create duplicate usernames');
    checkAuth($store->loginRecord("' OR 1=1 --") === null && $store->loginRecord('unknown_' . $tag) === null,
        'SQL syntax and unknown usernames cannot select an account');
    checkAuth(rejectsAuth(fn() => $createUser('../escape'), InvalidArgumentException::class),
        'The account store rejects traversal input independently of the HTTP controller');
    checkAuth(rejectsAuth(fn() => $folders->path('../escape'))
        && rejectsAuth(fn() => $folders->create('../escape'))
        && rejectsAuth(fn() => $folders->path(str_repeat('a', 31))),
        'Account folder operations reject malformed and traversal storage keys');
    $folderNames = array_values(array_filter(scandir($accountsRoot),
        static fn(string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($accountsRoot . '/' . $entry)));
    checkAuth(count($folderNames) === 2,
        'Rejected duplicate and invalid registrations leave no extra account folders');
    checkAuth(rejectsAuth(fn() => new AccountFolders(dirname(dirname(__DIR__)))),
        'Account storage roots cannot escape the application workspace');

    $stage = 'folder failure rollback';
    $failingFolders = new class($accountsRoot) extends AccountFolders {
        public function create(string $key): void
        {
            throw new RuntimeException('Synthetic account-folder failure');
        }
    };
    $failureStore = new AuthStore($admin, $fixtureKey, $failingFolders);
    $failureUsername = 'auth_fail_' . $tag;
    checkAuth(rejectsAuth(static function () use ($failureStore, $failureUsername, $passwordHash, &$users): void {
        $created = $failureStore->createUser($failureUsername, $passwordHash);
        $users[] = $created['id'];
    }), 'A private-folder creation failure aborts account creation');
    $failedAccount = $admin->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $failedAccount->execute([$failureUsername]);
    checkAuth((int) $failedAccount->fetchColumn() === 0,
        'Folder provisioning failures roll back the database account');

    $stage = 'persistent throttling';
    $bucket = 'auth-test-' . $tag;
    $rates[] = $store->fingerprint('rate:' . $bucket);
    checkAuth($store->allow($bucket, 2, 60) && $store->allow($bucket, 2, 60)
        && !$store->allow($bucket, 2, 60)
        && !AuthStore::connect($config)->allow($bucket, 2, 60),
        'Rate limits persist across connections after the allowed request count');
    $reset = $admin->prepare('UPDATE auth_rate_limits SET window_started = ? WHERE key_hash = ?');
    $reset->execute([time() - 61, $rates[0]]);
    checkAuth($store->allow($bucket, 2, 60), 'Expired rate-limit windows allow a fresh request');
    $passed = true;
} catch (Throwable $error) {
    fwrite(STDERR, 'Auth storage verification failed at ' . $stage . ' (' . get_class($error) . ').' . PHP_EOL);
} finally {
    if ($admin instanceof PDO) {
        try {
            foreach ([['users', 'id', $users], ['auth_rate_limits', 'key_hash', $rates]] as [$table, $column, $values]) {
                $delete = $admin->prepare('DELETE FROM ' . $table . ' WHERE ' . $column . ' = ?');
                foreach ($values as $value) $delete->execute([$value]);
            }
        } catch (Throwable) {
            fwrite(STDERR, 'Auth test database cleanup failed.' . PHP_EOL);
            $passed = false;
        }
    }
    try { cleanAuthRuntime($runtime); } catch (Throwable) {
        fwrite(STDERR, 'Auth test folder cleanup failed.' . PHP_EOL);
        $passed = false;
    }
    putenv($originalKey === false ? 'AUTH_APP_KEY' : 'AUTH_APP_KEY=' . $originalKey);
    putenv($originalAccountsPath === false ? 'AUTH_ACCOUNTS_PATH' : 'AUTH_ACCOUNTS_PATH=' . $originalAccountsPath);
    echo 'Auth test accounts and private test folders removed.' . PHP_EOL;
}
exit($passed ? 0 : 1);
