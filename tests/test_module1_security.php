<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Core\Database;
use Fleetify\Security\AccessControl;
use Fleetify\Security\ModuleGuard;
use Fleetify\Modules\Departments\DepartmentRepository;

$db = Database::connection();
$access = new AccessControl($db);
$modules = new ModuleGuard($db);
$departments = new DepartmentRepository($db);

$staff = [
    'id' => 1,
    'company_id' => 1,
    'role' => 'admin',
];

$tests = [];

try {
    $tests['Administrator permission granted'] =
        $access->can('departments.manage', $staff);

    $tests['Unknown permission denied'] =
        !$access->can('system.delete', $staff);

    $tests['Forged company identity denied'] =
        !$access->can('departments.manage', [
            'id' => 1,
            'company_id' => 3,
            'role' => 'admin',
        ]);

    $tests['Forged role denied'] =
        !$access->can('departments.manage', [
            'id' => 1,
            'company_id' => 1,
            'role' => 'driver',
        ]);

    $tests['Management department enabled'] =
        $modules->isEnabled('management', $staff);

    $tests['Unknown department denied'] =
        !$modules->isEnabled('nonexistent', $staff);

    $companyOne = $departments->listForCompany(1);
    $companyThree = $departments->listForCompany(3);

    $tests['Company 1 has nine departments'] =
        count($companyOne) === 9;

    $tests['Company 3 has nine departments'] =
        count($companyThree) === 9;

    $tests['Company 1 department records isolated'] =
        array_reduce(
            $companyOne,
            fn(bool $valid, array $row): bool =>
                $valid && (int)$row['company_id'] === 1,
            true
        );

    $tests['Company 3 department records isolated'] =
        array_reduce(
            $companyThree,
            fn(bool $valid, array $row): bool =>
                $valid && (int)$row['company_id'] === 3,
            true
        );

    echo "FLEETIFY 2.0 - MODULE 1 SECURITY TEST\n";
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

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "TEST FAILED: " . $exception->getMessage() . PHP_EOL
    );
    exit(1);
}