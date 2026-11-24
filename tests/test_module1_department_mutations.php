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

$companyId = 1;
$userId = 1;
$secondaryDepartmentId = 1;
$originalPrimaryId = 17;

$tests = [];
$createdAuditIds = [];
$assignmentCreated = false;
$baselineAuditId = 0;
$baselineAssignmentExists = false;

try {
    $q = $db->prepare(
        'SELECT COUNT(*) FROM staff_departments
         WHERE company_id=? AND user_id=? AND department_id=?'
    );
    $q->execute([$companyId, $userId, $secondaryDepartmentId]);
    $baselineAssignmentExists = (int)$q->fetchColumn() > 0;

    if ($baselineAssignmentExists) {
        throw new RuntimeException(
            'Secondary test assignment already exists; refusing to modify it.'
        );
    }

    $baselineAuditId = (int)$db->query(
        'SELECT COALESCE(MAX(id),0) FROM audit_logs'
    )->fetchColumn();

    $service->assignStaff(
        $actor,
        $userId,
        $secondaryDepartmentId,
        false
    );

    $assignmentCreated = true;

    $q = $db->prepare(
        'SELECT is_primary FROM staff_departments
         WHERE company_id=? AND user_id=? AND department_id=?'
    );
    $q->execute([$companyId, $userId, $secondaryDepartmentId]);

    $tests['Secondary department assignment created'] =
        (int)$q->fetchColumn() === 0;

    $q->execute([$companyId, $userId, $originalPrimaryId]);

    $tests['Original primary department preserved'] =
        (int)$q->fetchColumn() === 1;

    $q = $db->prepare(
        "SELECT id, new_values FROM audit_logs
         WHERE company_id=?
           AND actor_user_id=?
           AND action='staff.department_assigned'
           AND entity_id=?
           AND id>?
         ORDER BY id"
    );

    $q->execute([
        $companyId,
        $userId,
        $userId,
        $baselineAuditId
    ]);

    $audits = $q->fetchAll(PDO::FETCH_ASSOC);

    foreach ($audits as $audit) {
        $createdAuditIds[] = (int)$audit['id'];
    }

    $tests['Assignment audit record created'] =
        count($audits) === 1;

    $auditValues = count($audits) === 1
        ? json_decode($audits[0]['new_values'], true)
        : null;

    $tests['Audit identifies correct department'] =
        is_array($auditValues)
        && (int)($auditValues['department_id'] ?? 0)
            === $secondaryDepartmentId;

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'TEST ERROR: ' . $exception->getMessage() . PHP_EOL
    );

    $tests['Department mutation execution'] = false;

} finally {
    try {
        $db->beginTransaction();

        if ($assignmentCreated) {
            $q = $db->prepare(
                'DELETE FROM staff_departments
                 WHERE company_id=? AND user_id=?
                   AND department_id=? AND is_primary=0'
            );
            $q->execute([
                $companyId,
                $userId,
                $secondaryDepartmentId
            ]);
        }

        if ($createdAuditIds !== []) {
            $placeholders = implode(
                ',',
                array_fill(0, count($createdAuditIds), '?')
            );

            $q = $db->prepare(
                "DELETE FROM audit_logs
                 WHERE company_id=?
                   AND id IN ($placeholders)"
            );

            $q->execute(array_merge(
                [$companyId],
                $createdAuditIds
            ));
        }

        $db->commit();

    } catch (Throwable $cleanupError) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        fwrite(
            STDERR,
            'CLEANUP FAILED: ' . $cleanupError->getMessage() . PHP_EOL
        );

        exit(1);
    }
}

echo "FLEETIFY 2.0 - DEPARTMENT MUTATION TEST\n";
echo "---------------------------------------\n";

$passed = 0;

foreach ($tests as $name => $result) {
    echo $name . ': ' . ($result ? 'PASS' : 'FAIL') . PHP_EOL;

    if ($result) {
        $passed++;
    }
}

echo "---------------------------------------\n";
echo "RESULT: {$passed}/" . count($tests) . " tests passed\n";

exit($passed === count($tests) ? 0 : 1);