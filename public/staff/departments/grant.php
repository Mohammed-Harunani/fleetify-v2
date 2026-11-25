<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/config/bootstrap.php';

use Fleetify\Auth\StaffGuard;
use Fleetify\Core\Session;
use Fleetify\Helpers\Security;
use Fleetify\Modules\Departments\DepartmentService;
use Fleetify\Security\AccessControl;

Session::start();

// Accept POST requests only.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

// Require an authenticated staff session.
try {
    $staff = (new StaffGuard())->requireLogin();
} catch (RuntimeException $exception) {
    http_response_code(401);
    exit('Authentication required.');
}

// Enforce staff assignment permission.
$access = new AccessControl();

if (!$access->can('staff.assign', $staff)) {
    http_response_code(403);
    exit('Access denied.');
}

// Validate CSRF token using the existing Fleetify helper.
$csrfToken = $_POST['csrf_token'] ?? null;

if (
    !is_string($csrfToken) ||
    !Security::verifyCsrfToken($csrfToken)
) {
    http_response_code(403);
    exit('Invalid or expired form. Refresh and try again.');
}

// Validate staff ID.
$staffId = filter_var(
    $_POST['staff_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

// Validate office ID.
$officeId = filter_var(
    $_POST['office_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!is_int($staffId) || !is_int($officeId)) {
    http_response_code(400);
    exit('Invalid staff member or branch.');
}

// Grant access through the existing department service.
try {
    (new DepartmentService())->grantBranch(
        $staff,
        $staffId,
        $officeId
    );

    Session::set(
        'branch_access_flash',
        'Branch access granted successfully.'
    );

    header('Location: branches.php', true, 303);
    exit;

} catch (RuntimeException $exception) {
    $status = (int) $exception->getCode();

    if (!in_array($status, [400, 403, 404], true)) {
        $status = 400;
    }

    http_response_code($status);
    exit('Unable to grant branch access.');

} catch (Throwable $exception) {
    error_log(
        'Fleetify branch grant failed: ' . $exception->getMessage()
    );

    http_response_code(500);
    exit('Unable to process the branch access request.');
}
