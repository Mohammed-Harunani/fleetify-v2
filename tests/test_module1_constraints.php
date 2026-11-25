<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Core\Database;

$db = Database::connection();
$tests = [];

try {
    $db->beginTransaction();

    // Record the existing assignments before testing invalid inserts.
    $q = $db->query(
        'SELECT COUNT(*) FROM staff_departments
         WHERE company_id=1 AND user_id=1'
    );

    $initialAssignmentCount = (int) $q->fetchColumn();

    $crossCompanyRejected = false;

    try {
        $q = $db->prepare(
            'INSERT INTO staff_departments
             (company_id,user_id,department_id,is_primary)
             VALUES (1,1,2,0)'
        );

        $q->execute();

    } catch (PDOException $exception) {
        $crossCompanyRejected =
            $exception->getCode() === '23000';
    }

    $tests['Cross-company department assignment rejected'] =
        $crossCompanyRejected;

    $duplicatePrimaryRejected = false;

    try {
        $q = $db->prepare(
            'INSERT INTO staff_departments
             (company_id,user_id,department_id,is_primary)
             VALUES (1,1,1,1)'
        );

        $q->execute();

    } catch (PDOException $exception) {
        $duplicatePrimaryRejected =
            $exception->getCode() === '23000';
    }

    $tests['Second primary department rejected'] =
        $duplicatePrimaryRejected;

    $q = $db->query(
        'SELECT COUNT(*) FROM staff_departments
         WHERE company_id=1 AND user_id=1 AND is_primary=1'
    );

    $tests['Exactly one primary department remains'] =
        (int) $q->fetchColumn() === 1;

    $q = $db->query(
        'SELECT COUNT(*) FROM staff_departments
         WHERE company_id=1 AND user_id=1'
    );

    $finalAssignmentCount = (int) $q->fetchColumn();

    $tests['Rejected inserts created no assignments'] =
        $finalAssignmentCount === $initialAssignmentCount;

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'TEST ERROR: ' . $exception->getMessage() . PHP_EOL
    );

    $tests['Constraint test execution'] = false;

} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo "FLEETIFY 2.0 - MODULE 1 CONSTRAINT TEST\n";
echo "----------------------------------------\n";

$passed = 0;

foreach ($tests as $name => $result) {
    echo $name . ': ' . ($result ? 'PASS' : 'FAIL') . PHP_EOL;

    if ($result) {
        $passed++;
    }
}

echo "----------------------------------------\n";
echo "RESULT: {$passed}/" . count($tests) . " tests passed\n";

exit($passed === count($tests) ? 0 : 1);
