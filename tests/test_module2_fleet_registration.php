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
$vehicleId = null;
$registration = 'TEST-M2-' . strtoupper(bin2hex(random_bytes(5)));

$data = [
    'office_id' => 4,
    'registration_number' => $registration,
    'vehicle_type' => 'van',
    'capacity_kg' => 1500.00,
];

try {
    $vehicleId = $service->registerVehicle($actor, $data);

    $tests['Vehicle registration returns ID'] =
        $vehicleId > 0;

    $query = $db->prepare(
        'SELECT *
         FROM vehicles
         WHERE company_id = 1 AND id = ?'
    );

    $query->execute([$vehicleId]);
    $vehicle = $query->fetch(PDO::FETCH_ASSOC);

    $tests['Vehicle persisted correctly'] =
        is_array($vehicle)
        && $vehicle['registration_number'] === $registration
        && (int) $vehicle['office_id'] === 4
        && $vehicle['vehicle_type'] === 'van'
        && $vehicle['status'] === 'available';

    $query = $db->prepare(
        "SELECT new_values
         FROM audit_logs
         WHERE company_id = 1
           AND actor_user_id = 1
           AND action = 'vehicle.registered'
           AND entity_type = 'vehicles'
           AND entity_id = ?"
    );

    $query->execute([$vehicleId]);
    $audit = $query->fetchColumn();

    $values = $audit !== false
        ? json_decode((string) $audit, true)
        : null;

    $tests['Registration audit created'] =
        is_array($values)
        && ($values['registration_number'] ?? null) === $registration;

    try {
        $service->registerVehicle($actor, $data);
        $tests['Duplicate registration rejected'] = false;
    } catch (PDOException $exception) {
        $tests['Duplicate registration rejected'] =
            $exception->getCode() === '23000';
    }

    $invalidCases = [
        'Invalid vehicle type' => [
            'vehicle_type' => 'aircraft',
        ],
        'Negative capacity' => [
            'capacity_kg' => -100,
        ],
        'Missing registration' => [
            'registration_number' => '',
        ],
        'Invalid office' => [
            'office_id' => 999999,
        ],
    ];

    foreach ($invalidCases as $name => $changes) {
        try {
            $service->registerVehicle(
                $actor,
                array_merge($data, $changes)
            );

            $tests[$name . ' rejected'] = false;
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $tests[$name . ' rejected'] = true;
        }
    }

    $forgedActor = [
        'id' => 1,
        'company_id' => 1,
        'role' => 'driver',
    ];

    try {
        $service->registerVehicle($forgedActor, $data);
        $tests['Unauthorized registration rejected'] = false;
    } catch (RuntimeException $exception) {
        $tests['Unauthorized registration rejected'] =
            $exception->getCode() === 403;
    }

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'TEST ERROR: ' . $exception->getMessage() . PHP_EOL
    );

    $tests['Registration execution'] = false;

} finally {
    if ($vehicleId !== null) {
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $db->beginTransaction();

            $deleteAudit = $db->prepare(
                "DELETE FROM audit_logs
                 WHERE company_id = 1
                   AND entity_type = 'vehicles'
                   AND entity_id = ?
                   AND action = 'vehicle.registered'
                   AND actor_user_id = 1"
            );

            $deleteAudit->execute([$vehicleId]);

            $deleteVehicle = $db->prepare(
                'DELETE FROM vehicles
                 WHERE company_id = 1
                   AND id = ?
                   AND registration_number = ?'
            );

            $deleteVehicle->execute([
                $vehicleId,
                $registration,
            ]);

            if ($deleteVehicle->rowCount() !== 1) {
                throw new RuntimeException(
                    'Test vehicle cleanup did not delete exactly one row.'
                );
            }

            $db->commit();

            $tests['Temporary vehicle cleaned up'] = true;

        } catch (Throwable $cleanupError) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            fwrite(
                STDERR,
                'CLEANUP FAILED: '
                . $cleanupError->getMessage()
                . PHP_EOL
            );

            $tests['Temporary vehicle cleaned up'] = false;
        }
    }
}

echo "FLEETIFY V2 - MODULE 2 REGISTRATION TEST\n";
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