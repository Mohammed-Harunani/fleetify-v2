<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Core\Database;
use Fleetify\Security\AccessControl;
use Fleetify\Security\BranchScope;

$db = Database::connection();
$access = new AccessControl($db);
$scope = new BranchScope($db);

$staff = [
    'id' => 1,
    'company_id' => 1,
    'role' => 'admin',
];

$tests = [];

try {
    $db->beginTransaction();

    $global = $scope->apply('o', $staff);

    $tests['Global administrator remains tenant-scoped'] =
        str_contains($global['sql'], 'o.company_id = :scope_company');

    $tests['Global administrator has no branch assignment filter'] =
        !str_contains($global['sql'], 'staff_branch_access');

    $tests['Global administrator company binding correct'] =
        (int)$global['params']['scope_company'] === 1;

    $tests['Global administrator has no unused SQL bindings'] =
        array_keys($global['params']) === ['scope_company'];

    $globalQuery = $db->prepare(
        'SELECT COUNT(*) FROM staff_branch_access o WHERE ' . $global['sql']
    );

    $tests['Global administrator SQL executes successfully'] =
        $globalQuery->execute($global['params']);

    $remove = $db->prepare(
        "DELETE rp FROM role_permissions rp
         JOIN permissions p ON p.id=rp.permission_id
         WHERE rp.company_id=:company
           AND rp.role_code='admin'
           AND p.slug='branches.global'"
    );

    $remove->execute(['company'=>1]);

    $tests['Global permission removed inside transaction'] =
        !$access->can('branches.global', $staff);

    $restricted = $scope->apply('o', $staff);

    $tests['Restricted staff receives branch filter'] =
        str_contains($restricted['sql'], 'staff_branch_access');

    $tests['Restricted staff remains tenant-scoped'] =
        str_contains($restricted['sql'], 'o.company_id = :scope_company');

    $tests['Restricted staff ID binding correct'] =
        (int)$restricted['params']['scope_user'] === 1;

    $query = $db->prepare(
        'SELECT COUNT(*) FROM staff_branch_access o WHERE ' . $restricted['sql']
    );

    $query->execute($restricted['params']);

    $tests['Unassigned staff sees zero offices'] =
        (int)$query->fetchColumn() === 0;

    $db->rollBack();

    $tests['Global permission restored after rollback'] =
        $access->can('branches.global', $staff);

    echo "FLEETIFY 2.0 - MODULE 1 BRANCH SCOPE TEST\n";
    echo "------------------------------------------\n";

    $passed = 0;

    foreach ($tests as $name => $result) {
        echo $name . ': ' . ($result ? 'PASS' : 'FAIL') . PHP_EOL;

        if ($result) {
            $passed++;
        }
    }

    echo "------------------------------------------\n";
    echo "RESULT: {$passed}/" . count($tests) . " tests passed\n";

    exit($passed === count($tests) ? 0 : 1);

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "TEST FAILED: " . $exception->getMessage() . PHP_EOL
    );
    exit(1);

} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}