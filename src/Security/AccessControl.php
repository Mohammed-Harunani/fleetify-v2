<?php
declare(strict_types=1);
namespace Fleetify\Security;

use Fleetify\Core\Database;
use Fleetify\Auth\AuthService;
use PDO;
use RuntimeException;

final class AccessControl
{
    public function __construct(private ?PDO $db = null) {}

    public function can(string $permission, ?array $staff = null): bool
    {
        $staff ??= (new AuthService())->staffIdentity();
        if ($staff === null || empty($staff['id']) || empty($staff['company_id']) || empty($staff['role'])) {
            return false;
        }
        $db = $this->db ?? Database::connection();
        $sql = 'SELECT 1 FROM users u
                JOIN companies c ON c.id=u.company_id AND c.status=\'active\'
                JOIN role_permissions rp ON rp.company_id=u.company_id AND rp.role_code=u.role
                JOIN permissions p ON p.id=rp.permission_id
                WHERE u.company_id=:company AND u.id=:user AND u.status=\'active\'
                  AND u.role=:role AND p.slug=:permission LIMIT 1';
        $q=$db->prepare($sql);
        $q->execute(['company'=>(int)$staff['company_id'],'user'=>(int)$staff['id'],
                     'role'=>(string)$staff['role'],'permission'=>$permission]);
        return $q->fetchColumn() !== false;
    }

    public function requirePermission(string $permission, ?array $staff = null): void
    {
        if (!$this->can($permission,$staff)) {
            throw new RuntimeException('Access denied.',403);
        }
    }
}
