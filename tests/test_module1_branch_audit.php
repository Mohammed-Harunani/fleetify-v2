<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Core\Database;
use Fleetify\Modules\Departments\DepartmentService;

$db = Database::connection();
$service = new DepartmentService($db);

$actor = [
    'id' => 1,
    'company_id' => 1,
    'role' => 'admin',
];

$tests = [];
$baselineAuditId = 0;
$assignmentCreated = false;
$auditIds = [];

try {
    $q = $db->query(
        'SELECT COUNT(*) FROM staff_branch_access
         WHERE company_id=1 AND user_id=1 AND office_id=1'
    );

    if ((int)$q->fetchColumn() !== 0) {
        throw new RuntimeException(
            'Test branch assignment already exists. Refusing to modify it.'
        );
    }

    $baselineAuditId = (int)$db->query(
        'SELECT COALESCE(MAX(id),0) FROM audit_logs'
    )->fetchColumn();

    $service->grantBranch($actor, 1, 1);
    $assignmentCreated = true;

    $service->grantBranch($actor, 1, 1);

    $q = $db->query(
        'SELECT COUNT(*) FROM staff_branch_access
         WHERE company_id=1 AND user_id=1 AND office_id=1'
    );

    $tests['Duplicate grant creates one branch assignment'] =
        (int)$q->fetchColumn() === 1;

    $q = $db->prepare(
        "SELECT id,new_values FROM audit_logs
         WHERE company_id=1
           AND actor_user_id=1
           AND action='staff.branch_granted'
           AND entity_type='users'
           AND entity_id=1
           AND id>?
         ORDER BY id"
    );

    $q->execute([$baselineAuditId]);
    $audits = $q->fetchAll(PDO::FETCH_ASSOC);

    foreach ($audits as $audit) {
        $auditIds[] = (int)$audit['id'];
    }

    $tests['Duplicate grant creates one audit event'] =
        count($audits) === 1;

    $values = count($audits) === 1
        ? json_decode($audits[0]['new_values'], true)
        : null;

    $tests['Audit records correct office'] =
        is_array($values)
        && (int)($values['office_id'] ?? 0) === 1;

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'TEST ERROR: ' . $exception->getMessage() . PHP_EOL
    );

    $tests['Branch grant execution'] = false;

} finally {
    try {
        $db->beginTransaction();

        if ($assignmentCreated) {
            $q = $db->prepare(
                'DELETE FROM staff_branch_access
                 WHERE company_id=1 AND user_id=1 AND office_id=1'
            );
            $q->execute();
        }

        if ($auditIds !== []) {
            $placeholders = implode(',', array_fill(0, count($auditIds), '?'));

            $q = $db->prepare(
                "DELETE FROM audit_logs
                 WHERE company_id=1 AND id IN ($placeholders)"
            );

            $q->execute($auditIds);
        }

        $db->commit();

    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        fwrite(
            STDERR,
            'CLEANUP FAILED: ' . $exception->getMessage() . PHP_EOL
        );

        exit(1);
    }
}

echo "FLEETIFY 2.0 - BRANCH AUDIT TEST\n";
echo "---------------------------------\n";

$passed = 0;

foreach ($tests as $name => $result) {
    echo $name . ': ' . ($result ? 'PASS' : 'FAIL') . PHP_EOL;

    if ($result) {
        $passed++;
    }
}

echo "---------------------------------\n";
echo "RESULT: {$passed}/" . count($tests) . " tests passed\n";

exit($passed === count($tests) ? 0 : 1);