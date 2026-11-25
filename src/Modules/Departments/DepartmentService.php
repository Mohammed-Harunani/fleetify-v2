<?php
declare(strict_types=1);
namespace Fleetify\Modules\Departments;

use Fleetify\Core\Database;
use Fleetify\Security\AccessControl;
use PDO;
use RuntimeException;
use Throwable;

final class DepartmentService
{
    public function __construct(private ?PDO $db = null) {}

    public function setEnabled(array $actor,int $departmentId,bool $enabled): void
    {
        $db=$this->db ?? Database::connection();
        (new AccessControl($db))->requirePermission('departments.manage',$actor);
        $company=(int)$actor['company_id'];
        $user=(int)$actor['id'];
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT is_enabled FROM company_departments WHERE company_id=:company AND id=:id FOR UPDATE');
            $q->execute(['company'=>$company,'id'=>$departmentId]);
            $old=$q->fetchColumn();
            if ($old === false) throw new RuntimeException('Department not found.',404);
            $update=$db->prepare('UPDATE company_departments SET is_enabled=:enabled WHERE company_id=:company AND id=:id');
            $update->execute(['enabled'=>(int)$enabled,'company'=>$company,'id'=>$departmentId]);
            $audit=$db->prepare('INSERT INTO audit_logs (company_id,actor_user_id,action,entity_type,entity_id,old_values,new_values)
                VALUES (:company,:actor,:action,:type,:entity,:old,:new)');
            $audit->execute(['company'=>$company,'actor'=>$user,'action'=>'department.enabled_changed',
                'type'=>'company_departments','entity'=>$departmentId,
                'old'=>json_encode(['is_enabled'=>(bool)$old],JSON_THROW_ON_ERROR),
                'new'=>json_encode(['is_enabled'=>$enabled],JSON_THROW_ON_ERROR)]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function assignStaff(array $actor,int $staffId,int $departmentId,bool $primary=false): void
    {
        $db=$this->db ?? Database::connection();
        (new AccessControl($db))->requirePermission('staff.assign',$actor);
        $company=(int)$actor['company_id'];
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT id FROM users WHERE company_id=:company AND id=:id AND status=\'active\' FOR UPDATE');
            $q->execute(['company'=>$company,'id'=>$staffId]);
            if ($q->fetchColumn()===false) throw new RuntimeException('Staff member not found.',404);
            $q=$db->prepare('SELECT id FROM company_departments WHERE company_id=:company AND id=:id AND is_enabled=1');
            $q->execute(['company'=>$company,'id'=>$departmentId]);
            if ($q->fetchColumn()===false) throw new RuntimeException('Department unavailable.',404);
            if ($primary) {
                $q=$db->prepare('UPDATE staff_departments SET is_primary=0 WHERE company_id=:company AND user_id=:user');
                $q->execute(['company'=>$company,'user'=>$staffId]);
            }
            $q=$db->prepare('INSERT INTO staff_departments (company_id,user_id,department_id,is_primary)
                VALUES (:company,:user,:department,:primary)
                ON DUPLICATE KEY UPDATE is_primary=VALUES(is_primary)');
            $q->execute(['company'=>$company,'user'=>$staffId,'department'=>$departmentId,'primary'=>(int)$primary]);
            $q=$db->prepare('INSERT INTO audit_logs (company_id,actor_user_id,action,entity_type,entity_id,new_values)
                VALUES (:company,:actor,:action,:type,:entity,:new)');
            $q->execute(['company'=>$company,'actor'=>(int)$actor['id'],'action'=>'staff.department_assigned',
                'type'=>'users','entity'=>$staffId,
                'new'=>json_encode(['department_id'=>$departmentId,'is_primary'=>$primary],JSON_THROW_ON_ERROR)]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function grantBranch(array $actor,int $staffId,int $officeId): void
    {
        $db=$this->db ?? Database::connection();
        (new AccessControl($db))->requirePermission('staff.assign',$actor);
        $company=(int)$actor['company_id'];
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT id FROM users WHERE company_id=:company AND id=:id AND status=\'active\' FOR UPDATE');
            $q->execute(['company'=>$company,'id'=>$staffId]);
            if ($q->fetchColumn()===false) throw new RuntimeException('Staff member not found.',404);
            $q=$db->prepare('SELECT id FROM offices WHERE company_id=:company AND id=:id AND status=\'active\'');
            $q->execute(['company'=>$company,'id'=>$officeId]);
            if ($q->fetchColumn()===false) throw new RuntimeException('Office unavailable.',404);
            $q=$db->prepare('INSERT IGNORE INTO staff_branch_access (company_id,user_id,office_id) VALUES (:company,:user,:office)');
            $q->execute(['company'=>$company,'user'=>$staffId,'office'=>$officeId]);
            if ($q->rowCount() === 1) {
                $audit=$db->prepare('INSERT INTO audit_logs (company_id,actor_user_id,action,entity_type,entity_id,new_values)
                    VALUES (:company,:actor,:action,:type,:entity,:new)');
                $audit->execute(['company'=>$company,'actor'=>(int)$actor['id'],'action'=>'staff.branch_granted',
                    'type'=>'users','entity'=>$staffId,'new'=>json_encode(['office_id'=>$officeId],JSON_THROW_ON_ERROR)]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Revoke a company-scoped explicit branch access grant. */
    public function revokeBranch(array $actor, int $staffId, int $officeId): void
    {
        $db = $this->db ?? Database::connection();
        (new AccessControl($db))->requirePermission('staff.assign', $actor);
        $company = (int) $actor['company_id'];
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT id FROM users WHERE company_id=:company AND id=:id FOR UPDATE');
            $q->execute(['company'=>$company, 'id'=>$staffId]);
            if ($q->fetchColumn() === false) {
                throw new RuntimeException('Staff member not found.', 404);
            }

            $q = $db->prepare('SELECT id FROM offices WHERE company_id=:company AND id=:id');
            $q->execute(['company'=>$company, 'id'=>$officeId]);
            if ($q->fetchColumn() === false) {
                throw new RuntimeException('Office not found.', 404);
            }

            $q = $db->prepare('SELECT office_id FROM staff_branch_access WHERE company_id=:company AND user_id=:user AND office_id=:office FOR UPDATE');
            $q->execute(['company'=>$company, 'user'=>$staffId, 'office'=>$officeId]);
            if ($q->fetchColumn() === false) {
                throw new RuntimeException('Branch access grant not found.', 404);
            }

            $q = $db->prepare('DELETE FROM staff_branch_access WHERE company_id=:company AND user_id=:user AND office_id=:office');
            $q->execute(['company'=>$company, 'user'=>$staffId, 'office'=>$officeId]);
            if ($q->rowCount() !== 1) {
                throw new RuntimeException('Branch access grant could not be removed.', 409);
            }

            $audit = $db->prepare('INSERT INTO audit_logs (company_id,actor_user_id,action,entity_type,entity_id,old_values,new_values)
                VALUES (:company,:actor,:action,:type,:entity,:old,:new)');
            $audit->execute([
                'company'=>$company,
                'actor'=>(int) $actor['id'],
                'action'=>'staff.branch_revoked',
                'type'=>'users',
                'entity'=>$staffId,
                'old'=>json_encode(['office_id'=>$officeId], JSON_THROW_ON_ERROR),
                'new'=>json_encode(['office_id'=>null], JSON_THROW_ON_ERROR)
            ]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
