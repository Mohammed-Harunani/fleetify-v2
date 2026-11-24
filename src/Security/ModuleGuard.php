<?php
declare(strict_types=1);
namespace Fleetify\Security;

use Fleetify\Core\Database;
use Fleetify\Auth\AuthService;
use PDO;
use RuntimeException;

final class ModuleGuard
{
    public function __construct(private ?PDO $db = null) {}

    public function isEnabled(string $departmentCode, ?array $staff = null): bool
    {
        $staff ??= (new AuthService())->staffIdentity();
        if ($staff === null || empty($staff['company_id']) || empty($staff['id'])) return false;
        $db=$this->db ?? Database::connection();
        $q=$db->prepare('SELECT 1 FROM company_departments d
            JOIN companies c ON c.id=d.company_id AND c.status=\'active\'
            JOIN users u ON u.company_id=d.company_id AND u.id=:user AND u.status=\'active\'
            WHERE d.company_id=:company AND d.code=:code AND d.is_enabled=1 LIMIT 1');
        $q->execute(['company'=>(int)$staff['company_id'],'user'=>(int)$staff['id'],'code'=>$departmentCode]);
        return $q->fetchColumn() !== false;
    }

    public function requireEnabled(string $departmentCode, ?array $staff = null): void
    {
        if (!$this->isEnabled($departmentCode,$staff)) {
            throw new RuntimeException('Department module unavailable.',403);
        }
    }
}
