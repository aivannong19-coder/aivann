<?php

function loadLocalConfig(): array
{
    $path = dirname(__DIR__) . '/.env.local';
    $config = is_file($path) ? @parse_ini_file($path, false, INI_SCANNER_RAW) : [];
    if ($config === false) {
        throw new RuntimeException('Local configuration could not be loaded.');
    }
    return $config;
}

function configValue(array $config, string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value === false) {
        $value = $config[$name] ?? $default;
    }
    if (!is_scalar($value)) {
        throw new RuntimeException('Invalid server configuration.');
    }
    return (string) $value;
}
