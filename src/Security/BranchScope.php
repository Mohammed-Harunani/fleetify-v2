<?php
declare(strict_types=1);
namespace Fleetify\Security;

use Fleetify\Core\Database;
use PDO;
use InvalidArgumentException;

/** Apply to queries whose target table has company_id and office_id columns.
 * The caller must use returned SQL AND bind returned params on the same statement.
 */
final class BranchScope
{
    public function __construct(private ?PDO $db = null) {}

    public function apply(string $alias, array $staff, string $companyParam = 'scope_company', string $userParam = 'scope_user'): array
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$alias)
            || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$companyParam)
            || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$userParam)
            || $companyParam === $userParam) {
            throw new InvalidArgumentException('Invalid SQL alias or parameter name.');
        }
        $company=(int)($staff['company_id'] ?? 0);
        $user=(int)($staff['id'] ?? 0);
        if ($company <= 0 || $user <= 0) {
            throw new InvalidArgumentException('Authenticated staff identity required.');
        }
        $db=$this->db ?? Database::connection();
        $q=$db->prepare('SELECT 1 FROM users u JOIN companies c ON c.id=u.company_id
            WHERE u.company_id=:company AND u.id=:user AND u.status=\'active\'
              AND c.status=\'active\' LIMIT 1');
        $q->execute(['company'=>$company,'user'=>$user]);
        if ($q->fetchColumn() === false) {
            throw new InvalidArgumentException('Inactive or unknown staff identity.');
        }
        $params=[$companyParam=>$company];
        $predicate="{$alias}.company_id = :{$companyParam}";
        // Global visibility is a distinct explicit grant, not inferred from role name.
        if (!(new AccessControl($db))->can('branches.global',$staff)) {
            $params[$userParam]=$user;
            $predicate .= " AND EXISTS (SELECT 1 FROM staff_branch_access sba
                JOIN offices scope_office ON scope_office.company_id=sba.company_id
                   AND scope_office.id=sba.office_id AND scope_office.status='active'
                WHERE sba.company_id={$alias}.company_id AND sba.user_id=:{$userParam}
                  AND sba.office_id={$alias}.office_id)";
        }
        return ['sql'=>$predicate,'params'=>$params];
    }
}
