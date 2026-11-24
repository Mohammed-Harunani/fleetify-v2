<?php
declare(strict_types=1);
namespace Fleetify\Modules\Departments;

use Fleetify\Core\Database;
use PDO;

final class DepartmentRepository
{
    public function __construct(private ?PDO $db = null) {}

    public function listForCompany(int $companyId): array
    {
        $q=($this->db ?? Database::connection())->prepare(
            'SELECT id,company_id,code,name,is_enabled FROM company_departments WHERE company_id=:company ORDER BY name');
        $q->execute(['company'=>$companyId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $companyId,int $departmentId): ?array
    {
        $q=($this->db ?? Database::connection())->prepare(
            'SELECT id,company_id,code,name,is_enabled FROM company_departments WHERE company_id=:company AND id=:id');
        $q->execute(['company'=>$companyId,'id'=>$departmentId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
