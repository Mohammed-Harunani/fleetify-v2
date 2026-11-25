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

try {
    $vehicles = $service->listVehicles($actor);

    $tests['Fleet listing returns an array'] =
        is_array($vehicles);

    $tests['Fleet listing remains company-scoped'] =
        count(array_filter(
            $vehicles,
            static fn(array $vehicle): bool =>
                (int) $vehicle['company_id'] !== 1
        )) === 0;

    $expected = $db->prepare(
        'SELECT COUNT(*)
         FROM vehicles
         WHERE company_id = :company'
    );

    $expected->execute(['company' => 1]);

    $tests['Administrator sees company vehicles'] =
        count($vehicles) === (int) $expected->fetchColumn();

    if ($vehicles !== []) {
        $vehicleId = (int) $vehicles[0]['id'];

        $vehicle = $service->getVehicle($actor, $vehicleId);

        $tests['Vehicle retrieval returns correct ID'] =
            (int) $vehicle['id'] === $vehicleId;

        $tests['Vehicle retrieval remains company-scoped'] =
            (int) $vehicle['company_id'] === 1;
    } else {
        $tests['Vehicle retrieval fixture available'] = false;
    }

    try {
        $service->getVehicle($actor, 2147483647);
        $tests['Unknown vehicle rejected'] = false;
    } catch (RuntimeException $exception) {
        $tests['Unknown vehicle rejected'] =
            $exception->getCode() === 404;
    }

    $otherCompanyVehicle = $db->query(
        'SELECT id
         FROM vehicles
         WHERE company_id <> 1
         ORDER BY id
         LIMIT 1'
    )->fetchColumn();

    if ($otherCompanyVehicle !== false) {
        try {
            $service->getVehicle(
                $actor,
                (int) $otherCompanyVehicle
            );

            $tests['Cross-company vehicle rejected'] = false;
        } catch (RuntimeException $exception) {
            $tests['Cross-company vehicle rejected'] =
                $exception->getCode() === 404;
        }
    } else {
        echo "SKIP: No cross-company vehicle fixture available.\n";
    }

    $unauthorisedActor = [
        'id' => 1,
        'company_id' => 1,
        'role' => 'driver',
    ];

    try {
        $service->listVehicles($unauthorisedActor);
        $tests['Forged driver role rejected'] = false;
    } catch (RuntimeException $exception) {
        $tests['Forged driver role rejected'] =
            $exception->getCode() === 403;
    }

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'TEST ERROR: ' . $exception->getMessage() . PHP_EOL
    );

    $tests['Fleet read execution'] = false;
}

echo "FLEETIFY V2 - MODULE 2 FLEET READ TEST\n";
echo "--------------------------------------\n";

$passed = 0;

foreach ($tests as $name => $result) {
    echo $name . ': ' . ($result ? 'PASS' : 'FAIL') . PHP_EOL;

    if ($result) {
        $passed++;
    }
}

echo "--------------------------------------\n";
echo "RESULT: {$passed}/" . count($tests) . " tests passed\n";

exit($passed === count($tests) ? 0 : 1);