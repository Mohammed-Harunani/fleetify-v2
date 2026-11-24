<?php
declare(strict_types=1);

namespace Fleetify\Helpers;

use RuntimeException;

final class Security
{
    public static function escape(?string $value): string
    {
        return htmlspecialchars(
            $value ?? '',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException(
                'A session must be started before generating a CSRF token.'
            );
        }

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(
                random_bytes(32)
            );
        }

        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrfToken(?string $token): bool
    {
        if (
            session_status() !== PHP_SESSION_ACTIVE ||
            !is_string($token) ||
            !isset($_SESSION['csrf_token']) ||
            !is_string($_SESSION['csrf_token'])
        ) {
            return false;
        }

        return hash_equals(
            $_SESSION['csrf_token'],
            $token
        );
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . self::escape(self::csrfToken())
            . '">';
    }
}
