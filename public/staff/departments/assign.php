<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/config/bootstrap.php';

use Fleetify\Auth\StaffGuard;
use Fleetify\Core\Session;
use Fleetify\Helpers\Security;
use Fleetify\Modules\Departments\DepartmentService;
use Fleetify\Security\AccessControl;

Session::start();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

try {
    $staff = (new StaffGuard())->requireLogin();
} catch (RuntimeException $exception) {
    http_response_code(401);
    exit('Authentication required.');
}

$access = new AccessControl();

if (!$access->can('staff.assign', $staff)) {
    http_response_code(403);
    exit('Access denied.');
}

$token = $_POST['csrf_token'] ?? null;

if (!is_string($token) || !Security::verifyCsrfToken($token)) {
    http_response_code(403);
    exit('Invalid or expired form.');
}

$staffId = filter_var(
    $_POST['staff_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$departmentId = filter_var(
    $_POST['department_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$type = $_POST['assignment_type'] ?? null;

if (
    !is_int($staffId)
    || !is_int($departmentId)
    || !is_string($type)
    || !in_array($type, ['primary', 'secondary'], true)
) {
    http_response_code(400);
    exit('Invalid assignment details.');
}

try {
    (new DepartmentService())->assignStaff(
        $staff,
        $staffId,
        $departmentId,
        $type === 'primary'
    );

    Session::set(
        'staff_assignment_flash',
        'Staff department assignment saved successfully.'
    );

    header('Location: staff.php', true, 303);
    exit;

} catch (RuntimeException $exception) {
    $code = $exception->getCode();

    http_response_code(
        in_array($code, [403, 404], true) ? $code : 400
    );

    exit('Unable to assign department.');
}
