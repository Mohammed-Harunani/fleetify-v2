<?php
declare(strict_types=1);

namespace Fleetify\Auth;

use Fleetify\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class IpRateLimiter
{
    private const MAX_ATTEMPTS = 20;
    private const WINDOW_MINUTES = 15;
    private const LOCK_MINUTES = 15;

    private PDO $pdo;
    private string $secret;

    public function __construct()
    {
        $this->pdo = Database::connection();

        $path = dirname(__DIR__, 4) . '/fleetify-v2-secrets/ip_hash_secret';

        $encoded = @file_get_contents($path);

        if ($encoded === false) {
            throw new RuntimeException(
                'IP rate limiter secret unavailable.'
            );
        }

        $secret = base64_decode(trim($encoded), true);

        if ($secret === false || strlen($secret) !== 32) {
            throw new RuntimeException(
                'Invalid IP rate limiter secret.'
            );
        }

        $this->secret = $secret;
    }

    public function isLocked(string $ip): bool
    {
        $hash = $this->hashIp($ip);

        $statement = $this->pdo->prepare(
            'SELECT (locked_until > NOW()) AS is_locked
             FROM staff_ip_login_attempts
             WHERE ip_hash = :ip_hash'
        );

        $statement->execute(['ip_hash' => $hash]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function recordFailure(string $ip): void
    {
        $hash = $this->hashIp($ip);

        $this->pdo->beginTransaction();

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO staff_ip_login_attempts
                    (ip_hash, failed_attempts)
                 VALUES (:ip_hash, 0)
                 ON DUPLICATE KEY UPDATE id = id'
            );

            $insert->execute(['ip_hash' => $hash]);

            $select = $this->pdo->prepare(
                'SELECT failed_attempts,
                        first_failed_at,
                        locked_until,
                        NOW() AS db_now
                 FROM staff_ip_login_attempts
                 WHERE ip_hash = :ip_hash
                 FOR UPDATE'
            );

            $select->execute(['ip_hash' => $hash]);
            $row = $select->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                throw new RuntimeException(
                    'IP rate limit record unavailable.'
                );
            }

            $now = strtotime($row['db_now']);

            if (
                $row['locked_until'] !== null
                && strtotime($row['locked_until']) > $now
            ) {
                $this->pdo->commit();
                return;
            }

            $firstFailed = $row['first_failed_at'] !== null
                ? strtotime($row['first_failed_at'])
                : false;

            $expired = $firstFailed === false
                || $firstFailed <= $now
                    - self::WINDOW_MINUTES * 60;

            $attempts = $expired
                ? 1
                : (int) $row['failed_attempts'] + 1;

            $firstFailedAt = $expired
                ? $row['db_now']
                : $row['first_failed_at'];

            $update = $this->pdo->prepare(
                'UPDATE staff_ip_login_attempts
                 SET failed_attempts = :attempts,
                     first_failed_at = :first_failed_at,
                     locked_until = CASE
                         WHEN :lock_required = 1
                         THEN DATE_ADD(
                             NOW(), INTERVAL 15 MINUTE
                         )
                         ELSE NULL
                     END
                 WHERE ip_hash = :ip_hash'
            );

            $update->execute([
                'attempts' => $attempts,
                'first_failed_at' => $firstFailedAt,
                'lock_required' =>
                    $attempts >= self::MAX_ATTEMPTS ? 1 : 0,
                'ip_hash' => $hash,
            ]);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function clearFailures(string $ip): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM staff_ip_login_attempts
             WHERE ip_hash = :ip_hash'
        );

        $statement->execute([
            'ip_hash' => $this->hashIp($ip),
        ]);
    }

    private function hashIp(string $ip): string
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            throw new RuntimeException(
                'Invalid client IP address.'
            );
        }

        return hash_hmac(
            'sha256',
            $binary,
            $this->secret
        );
    }
}
