<?php
declare(strict_types=1);

namespace Fleetify\Repositories;

use Fleetify\Core\Database;
use PDO;

final class UserRepository
{
    public function findByEmail(
        int $companyId,
        string $email
    ): ?array {
        $pdo = Database::connection();

        $statement = $pdo->prepare(
            'SELECT
                id,
                company_id,
                office_id,
                name,
                email,
                password_hash,
                role,
                status
             FROM users
             WHERE company_id = :company_id
               AND email = :email
             LIMIT 1'
        );

        $statement->execute([
            'company_id' => $companyId,
            'email' => $email,
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return $user === false ? null : $user;
    }

    public function findById(
        int $companyId,
        int $userId
    ): ?array {
        $pdo = Database::connection();

        $statement = $pdo->prepare(
            "SELECT
                users.id,
                users.company_id,
                users.office_id,
                users.name,
                users.email,
                users.role,
                users.status
             FROM users
             INNER JOIN companies
                 ON companies.id = users.company_id
             WHERE users.company_id = :company_id
               AND users.id = :user_id
               AND companies.status = 'active'
             LIMIT 1"
        );

        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return $user === false ? null : $user;
    }
}
