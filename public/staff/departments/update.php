<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/config/bootstrap.php';

use Fleetify\Auth\StaffGuard;
use Fleetify\Core\Session;
use Fleetify\Helpers\Security;
use Fleetify\Modules\Departments\DepartmentService;
use Fleetify\Security\AccessControl;

Session::start();

// Only accept POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

// Require an authenticated staff session.
try {
    $staff = (new StaffGuard())->requireLogin();
} catch (RuntimeException $exception) {
    http_response_code(401);
    exit('Authentication required.');
}

// Enforce database-backed permissions.
$access = new AccessControl();

if (!$access->can('departments.manage', $staff)) {
    http_response_code(403);
    exit('Access denied.');
}

// Validate CSRF protection.
$token = $_POST['csrf_token'] ?? null;

if (!is_string($token) || !Security::verifyCsrfToken($token)) {
    http_response_code(403);
    exit('Invalid or expired form.');
}

// Validate department ID and requested status.
$departmentId = filter_var(
    $_POST['department_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$status = $_POST['enabled'] ?? null;

if (
    $departmentId === false ||
    $departmentId === null ||
    !is_string($status) ||
    !in_array($status, ['0', '1'], true)
) {
    http_response_code(400);
    exit('Invalid department update.');
}

try {
    $service = new DepartmentService();

    $service->setEnabled(
        $staff,
        $departmentId,
        $status === '1'
    );

    Session::set(
        'department_flash',
        'Department status updated successfully.'
    );

    header('Location: index.php', true, 303);
    exit;

} catch (RuntimeException $exception) {
    $code = $exception->getCode();

    http_response_code(
        in_array($code, [403, 404], true) ? $code : 400
    );

    exit('Unable to update department.');
}
