<?php
declare(strict_types=1);

namespace Fleetify\Core;

use RuntimeException;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent()) {
            throw new RuntimeException(
                'Cannot start a session after headers have been sent.'
            );
        }

        if (session_status() === PHP_SESSION_DISABLED) {
            throw new RuntimeException(
                'PHP sessions are disabled.'
            );
        }

        $isHttps = !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off';

        session_name('fleetify_session');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        if (!session_start()) {
            throw new RuntimeException(
                'Unable to start the session.'
            );
        }
    }

    public static function regenerate(): void
    {
        self::start();

        if (!session_regenerate_id(true)) {
            throw new RuntimeException(
                'Unable to regenerate the session ID.'
            );
        }
    }

    public static function get(
        string $key,
        mixed $default = null
    ): mixed {
        self::start();

        return $_SESSION[$key] ?? $default;
    }

    public static function set(
        string $key,
        mixed $value
    ): void {
        self::start();

        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        self::start();

        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        self::start();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        session_destroy();
    }
}
