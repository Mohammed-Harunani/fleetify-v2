<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Core\Database;
use Fleetify\Modules\Fleet\FleetService;

$db = Database::connection();
$service = new FleetService($db);

$actor = [
    'id' => 1,
    'company_id' => 1,
    'role' => 'admin',
];

$tests = [];
$driverId = null;
$assignmentId = null;
$testToken = bin2hex(random_bytes(8));
$testEmail = 'fleet-m2-' . $testToken . '@example.invalid';
$cleanupSucceeded = true;

try {
    $insertDriver = $db->prepare(
        "INSERT INTO users
            (company_id, office_id, name, email,
             password_hash, role, status)
         VALUES
            (1, 4, :name, :email, :hash, 'driver', 'active')"
    );

    $insertDriver->execute([
        'name' => 'Module 2 Test Driver',
        'email' => $testEmail,
        'hash' => password_hash(
            bin2hex(random_bytes(32)),
            PASSWORD_DEFAULT
        )
    ]);

    $driverId = (int) $db->lastInsertId();

    $tests['Temporary driver created'] = $driverId > 0;

    $assignmentId = $service->assignDriver(
        $actor,
        2,
        $driverId,
        'Module 2 regression test'
    );

    $tests['Driver assignment created'] =
        $assignmentId > 0;

    $query = $db->prepare(
        'SELECT *
         FROM vehicle_driver_assignments
         WHERE company_id = 1
           AND id = ?'
    );

    $query->execute([$assignmentId]);
    $assignment = $query->fetch(PDO::FETCH_ASSOC);

    $tests['Assignment persisted correctly'] =
        is_array($assignment)
        && (int) $assignment['vehicle_id'] === 2
        && (int) $assignment['driver_user_id'] === $driverId
        && $assignment['ended_at'] === null;

    $history = $service->listDriverAssignments($actor, 2);

    $tests['Assignment appears in history'] =
        count(array_filter(
            $history,
            static fn(array $row): bool =>
                (int) $row['id'] === $assignmentId
        )) === 1;

    try {
        $service->assignDriver($actor, 2, $driverId);
        $tests['Duplicate vehicle assignment rejected'] = false;
    } catch (RuntimeException $exception) {
        $tests['Duplicate vehicle assignment rejected'] =
            $exception->getCode() === 409;
    }

    try {
        $service->assignDriver($actor, 1, $driverId);
        $tests['Driver double-booking rejected'] = false;
    } catch (RuntimeException $exception) {
        $tests['Driver double-booking rejected'] =
            $exception->getCode() === 409;
    }

    try {
        $service->assignDriver($actor, 1, 1);
        $tests['Non-driver account rejected'] = false;
    } catch (RuntimeException $exception) {
        $tests['Non-driver account rejected'] =
            $exception->getCode() === 404;
    }

    try {
        $service->assignDriver($actor, 3, $driverId);
        $tests['Cross-company vehicle rejected'] = false;
    } catch (RuntimeException $exception) {
        $tests['Cross-company vehicle rejected'] =
            $exception->getCode() === 404;
    }

    $auditQuery = $db->prepare(
        "SELECT COUNT(*)
         FROM audit_logs
         WHERE company_id = 1
           AND actor_user_id = 1
           AND action = 'vehicle.driver_assigned'
           AND entity_type = 'vehicle_driver_assignments'
           AND entity_id = ?"
    );

    $auditQuery->execute([$assignmentId]);

    $tests['Assignment audit created'] =
        (int) $auditQuery->fetchColumn() === 1;

    $service->releaseDriver($actor, $assignmentId);

    $query->execute([$assignmentId]);
    $released = $query->fetch(PDO::FETCH_ASSOC);

    $tests['Driver assignment released'] =
        is_array($released)
        && $released['ended_at'] !== null;

    $releaseAudit = $db->prepare(
        "SELECT COUNT(*)
         FROM audit_logs
         WHERE company_id = 1
           AND actor_user_id = 1
           AND action = 'vehicle.driver_released'
           AND entity_type = 'vehicle_driver_assignments'
           AND entity_id = ?"
    );

    $releaseAudit->execute([$assignmentId]);

    $tests['Release audit created'] =
        (int) $releaseAudit->fetchColumn() === 1;

    try {
        $service->releaseDriver($actor, $assignmentId);
        $tests['Repeated release rejected'] = false;
    } catch (RuntimeException $exception) {
        $tests['Repeated release rejected'] =
            $exception->getCode() === 409;
    }

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'TEST ERROR: ' . $exception->getMessage() . PHP_EOL
    );

    $tests['Driver assignment execution'] = false;

} finally {
    try {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $db->beginTransaction();

        if ($assignmentId !== null) {
            $deleteAudit = $db->prepare(
                "DELETE FROM audit_logs
                 WHERE company_id = 1
                   AND entity_type = 'vehicle_driver_assignments'
                   AND entity_id = ?
                   AND actor_user_id = 1
                   AND action IN (
                       'vehicle.driver_assigned',
                       'vehicle.driver_released'
                   )"
            );

            $deleteAudit->execute([$assignmentId]);

            $deleteAssignment = $db->prepare(
                'DELETE FROM vehicle_driver_assignments
                 WHERE company_id = 1
                   AND id = ?
                   AND driver_user_id = ?'
            );

            $deleteAssignment->execute([
                $assignmentId,
                $driverId
            ]);

            if ($deleteAssignment->rowCount() !== 1) {
                throw new RuntimeException(
                    'Assignment cleanup failed.'
                );
            }
        }

        if ($driverId !== null) {
            $deleteDriver = $db->prepare(
                'DELETE FROM users
                 WHERE company_id = 1
                   AND id = ?
                   AND email = ?'
            );

            $deleteDriver->execute([
                $driverId,
                $testEmail
            ]);

            if ($deleteDriver->rowCount() !== 1) {
                throw new RuntimeException(
                    'Driver cleanup failed.'
                );
            }
        }

        $db->commit();

    } catch (Throwable $cleanupError) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $cleanupSucceeded = false;

        fwrite(
            STDERR,
            'CLEANUP FAILED: '
            . $cleanupError->getMessage()
            . PHP_EOL
        );
    }
}

$tests['Temporary records cleaned up'] = $cleanupSucceeded;

echo "FLEETIFY V2 - MODULE 2 DRIVER ASSIGNMENT TEST\n";
echo "---------------------------------------------\n";

$passed = 0;

foreach ($tests as $name => $result) {
    echo $name . ': ' . ($result ? 'PASS' : 'FAIL') . PHP_EOL;

    if ($result) {
        $passed++;
    }
}

echo "---------------------------------------------\n";
echo "RESULT: {$passed}/" . count($tests) . " tests passed\n";

exit($passed === count($tests) ? 0 : 1);