<?php
declare(strict_types=1);

/**
 * Fleetify 2.0
 * Environment-based PDO Database Configuration.
 */

$environment = static function (
    string $name,
    string $default
): string {
    $value = getenv($name);

    return $value === false || $value === ''
        ? $default
        : $value;
};

$port = filter_var(
    $environment('FLEETIFY_DB_PORT', '3306'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 65535]]
);

if ($port === false) {
    throw new RuntimeException('Invalid database port configuration.');
}

return [
    'host' => $environment('FLEETIFY_DB_HOST', '127.0.0.1'),
    'port' => $port,
    'database' => $environment('FLEETIFY_DB_NAME', 'fleetify_v2'),
    'username' => $environment('FLEETIFY_DB_USER', 'root'),
    'password' => $environment('FLEETIFY_DB_PASSWORD', ''),
    'charset' => 'utf8mb4',

    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
    ],
];