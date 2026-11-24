<?php
declare(strict_types=1);

/**
 * Fleetify 2.0
 * Application Bootstrap
 *
 * Registers the Fleetify PSR-4-style class autoloader.
 */

define('FLEETIFY_ROOT', dirname(__DIR__));

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'Fleetify\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr(
            $class,
            strlen($prefix)
        );

        $file = FLEETIFY_ROOT
            . '/src/'
            . str_replace('\\', '/', $relativeClass)
            . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
);
