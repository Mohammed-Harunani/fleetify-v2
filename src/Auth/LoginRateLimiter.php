<?php
declare(strict_types=1);

namespace Fleetify\Auth;

use Fleetify\Core\Database;
use PDO;

final class LoginRateLimiter
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;
    private const LOCK_MINUTES = 15;

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function isLocked(
        int $companyId,
        string $email
    ): bool {
        $statement = $this->pdo->prepare(
            'SELECT locked_until,
                    (locked_until > NOW()) AS is_locked
             FROM staff_login_attempts
             WHERE company_id = :company_id
               AND email = :email
             LIMIT 1'
        );

        $statement->execute([
            'company_id' => $companyId,
            'email' => $this->normalizeEmail($email),
        ]);

        $row = $statement->fetch();

        if (!$row || $row['locked_until'] === null) {
            return false;
        }

        return (int) $row['is_locked'] === 1;
    }

    public function recordFailure(
        int $companyId,
        string $email
    ): void {
        $email = $this->normalizeEmail($email);

        $this->pdo->beginTransaction();

        try {
            // Create the row if it does not exist.
            $insert = $this->pdo->prepare(
                'INSERT INTO staff_login_attempts
                    (company_id, email, failed_attempts)
                 VALUES (:company_id, :email, 0)
                 ON DUPLICATE KEY UPDATE id = id'
            );

            $insert->execute([
                'company_id' => $companyId,
                'email' => $email,
            ]);

            // Lock the account row to prevent concurrent
            // requests from losing failed-attempt updates.
            $select = $this->pdo->prepare(
                'SELECT failed_attempts,
                        first_failed_at,
                        locked_until,
                        NOW() AS db_now
                 FROM staff_login_attempts
                 WHERE company_id = :company_id
                   AND email = :email
                 FOR UPDATE'
            );

            $select->execute([
                'company_id' => $companyId,
                'email' => $email,
            ]);

            $row = $select->fetch();

            $now = strtotime($row['db_now']);

            // Preserve an existing active lockout.
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

            $windowExpired = $firstFailed === false
                || $firstFailed <= $now - (self::WINDOW_MINUTES * 60);

            $attempts = $windowExpired
                ? 1
                : (int) $row['failed_attempts'] + 1;

            $firstFailedAt = $windowExpired
                ? $row['db_now']
                : $row['first_failed_at'];

            $lockedUntil = $attempts >= self::MAX_ATTEMPTS
                ? $this->databaseLockExpiry()
                : null;

            $update = $this->pdo->prepare(
                'UPDATE staff_login_attempts
                 SET failed_attempts = :attempts,
                     first_failed_at = :first_failed_at,
                     locked_until = :locked_until
                 WHERE company_id = :company_id
                   AND email = :email'
            );

            $update->execute([
                'attempts' => $attempts,
                'first_failed_at' => $firstFailedAt,
                'locked_until' => $lockedUntil,
                'company_id' => $companyId,
                'email' => $email,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function clearFailures(
        int $companyId,
        string $email
    ): void {
        $statement = $this->pdo->prepare(
            'DELETE FROM staff_login_attempts
             WHERE company_id = :company_id
               AND email = :email'
        );

        $statement->execute([
            'company_id' => $companyId,
            'email' => $this->normalizeEmail($email),
        ]);
    }

    private function databaseLockExpiry(): string
    {
        $minutes = self::LOCK_MINUTES;

        $statement = $this->pdo->query(
            "SELECT DATE_ADD(NOW(), INTERVAL {$minutes} MINUTE)"
        );

        return (string) $statement->fetchColumn();
    }
    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
