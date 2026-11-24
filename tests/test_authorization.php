<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Auth\Authorization;

$tests = [
    'Admin can manage users' =>
        Authorization::can('admin', 'users.manage'),

    'Admin can manage vehicles' =>
        Authorization::can('admin', 'vehicles.manage'),

    'Branch manager can view reports' =>
        Authorization::can('branch_manager', 'reports.view'),

    'Branch manager cannot manage users' =>
        !Authorization::can('branch_manager', 'users.manage'),

    'Dispatcher can assign consignments' =>
        Authorization::can('dispatcher', 'consignments.assign'),

    'Dispatcher cannot view reports' =>
        !Authorization::can('dispatcher', 'reports.view'),

    'Driver can view consignments' =>
        Authorization::can('driver', 'consignments.view'),

    'Driver cannot update consignments' =>
        !Authorization::can('driver', 'consignments.update'),

    'Unknown role is denied' =>
        !Authorization::can('unknown', 'users.manage'),

    'Unknown permission is denied' =>
        !Authorization::can('admin', 'system.delete'),
];

try {
    Authorization::requirePermission(
        'driver',
        'users.manage'
    );

    $tests['Denied permission throws exception'] = false;
} catch (RuntimeException $exception) {
    $tests['Denied permission throws exception'] = true;
}

echo "FLEETIFY 2.0 - AUTHORIZATION TEST\n";
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
