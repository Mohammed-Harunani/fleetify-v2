<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Auth\AuthService;
use Fleetify\Core\Session;

if (PHP_SAPI !== 'cli') {
    exit("CLI execution only.\n");
}

// Simulate the client IP supplied by Apache during web requests.
$_SERVER['REMOTE_ADDR'] = '192.0.2.51';

$password = getenv('FLEETIFY_TEST_PASSWORD');

if (!is_string($password) || $password === '') {
    exit("ERROR: Test password not provided.\n");
}

try {
    Session::start();

    $auth = new AuthService();
    $results = [];

    // Test 1: Correct password
    $results['VALID LOGIN'] = $auth->login(
        1,
        'admin@fleetify.test',
        $password
    );

    // Test 2: Retrieve authenticated staff
    $staff = $auth->staffIdentity();

    $results['STAFF IDENTITY'] =
        $staff !== null
        && (int) $staff['company_id'] === 1
        && $staff['role'] === 'admin';

    // Test 3: Reject incorrect password
    $results['INVALID PASSWORD REJECTED'] =
        !$auth->login(
            1,
            'admin@fleetify.test',
            'definitely-not-the-correct-password'
        );

    // Test 4: Reject cross-company login
    $results['CROSS-COMPANY LOGIN REJECTED'] =
        !$auth->login(
            3,
            'admin@fleetify.test',
            $password
        );

    // Test 5: Logout
    $auth->logout();

    $results['LOGOUT'] =
        session_status() !== PHP_SESSION_ACTIVE;

    echo "FLEETIFY 2.0 - AUTHENTICATION TEST\n";
    echo "---------------------------------\n";

    foreach ($results as $name => $passed) {
        echo $name . ': '
            . ($passed ? 'PASS' : 'FAIL')
            . PHP_EOL;
    }

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "ERROR: " . $exception->getMessage() . PHP_EOL
    );
    exit(1);
}
