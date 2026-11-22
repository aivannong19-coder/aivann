<?php
declare(strict_types=1);

/** Account content is private; usernames never become filesystem paths. */
class AccountFolders
{
    private string $root;
    private string $workspace;

    public function __construct(?string $root = null)
    {
        $workspace = realpath(dirname(__DIR__));
        if ($workspace === false) throw new RuntimeException('Account storage is unavailable.');
        $this->workspace = str_replace('\\', '/', $workspace);
        $this->root = rtrim(str_replace('\\', '/', $root ?? $workspace . '/storage/accounts'), '/');
        $prefix = $this->workspace . '/';
        if (!$this->samePath(substr($this->root, 0, strlen($prefix)), $prefix)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f]~', $this->root)) {
            throw new RuntimeException('Account storage must stay inside the application directory.');
        }
    }

    private function samePath(string $left, string $right): bool
    {
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    public function initialize(): void
    {
        $current = $this->workspace;
        foreach (explode('/', substr($this->root, strlen($this->workspace) + 1)) as $component) {
            if ($component === '') throw new RuntimeException('Invalid account storage path.');
            $current .= '/' . $component;
            if (is_link($current) || (file_exists($current) && !is_dir($current))) {
                throw new RuntimeException('Account storage is unavailable.');
            }
            if (!is_dir($current) && !@mkdir($current, 0700) && !is_dir($current)) {
                throw new RuntimeException('Account storage could not be created.');
            }
            $resolved = realpath($current);
            if ($resolved === false || !$this->samePath(str_replace('\\', '/', $resolved), $current)) {
                throw new RuntimeException('Account storage cannot use redirected directories.');
            }
        }
        $protection = $this->root . '/.htaccess';
        $contents = "Require all denied\nOptions -Indexes\n";
        if (is_link($protection) || (file_exists($protection) && !is_file($protection))) {
            throw new RuntimeException('Account folder protection is unavailable.');
        }
        if (!file_exists($protection)) {
            $handle = @fopen($protection, 'x+b');
            if ($handle !== false) {
                try {
                    if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                        throw new RuntimeException('Account folder protection could not be saved.');
                    }
                } finally {
                    fclose($handle);
                }
            }
        }
        if (@file_get_contents($protection) !== $contents) {
            throw new RuntimeException('Account folder protection is unavailable.');
        }
    }

    public function path(string $key): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $key)) throw new RuntimeException('Invalid account folder identifier.');
        return $this->root . '/' . $key;
    }

    public function create(string $key): void
    {
        $this->initialize();
        $path = $this->path($key);
        if (file_exists($path) || is_link($path) || !@mkdir($path, 0700)) {
            throw new RuntimeException('The account folder could not be created.');
        }
    }

    /** Only a newly created, empty folder may be removed during rollback. */
    public function removeEmpty(string $key): bool
    {
        $path = $this->path($key);
        $resolved = realpath($path);
        if (is_link($path) || $resolved === false || !is_dir($path)
            || !$this->samePath(str_replace('\\', '/', $resolved), $path)) return false;
        return @rmdir($path);
    }
}
