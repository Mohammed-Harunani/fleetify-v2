<?php
declare(strict_types=1);

$_SERVER['REMOTE_ADDR'] = '192.0.2.52';
require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Auth\AuthService;
use Fleetify\Auth\StaffGuard;
use Fleetify\Core\Session;

if (PHP_SAPI !== 'cli') {
    exit("CLI execution only.\n");
}

$password = getenv('FLEETIFY_TEST_PASSWORD');

if (!is_string($password) || $password === '') {
    exit("ERROR: Test password not provided.\n");
}

$auth = new AuthService();
$guard = new StaffGuard();
$tests = [];

try {
    Session::start();

    // Test 1: Unauthenticated access must be denied.
    $auth->logout();

    try {
        $guard->requireLogin();
        $tests['Unauthenticated access rejected'] = false;
    } catch (RuntimeException $exception) {
        $tests['Unauthenticated access rejected'] =
            $exception->getCode() === 401;
    }

    // Test 2: Administrator login succeeds.
    $tests['Administrator login'] = $auth->login(
        1,
        'admin@fleetify.test',
        $password
    );

    // Test 3: Authenticated administrator identity.
    $staff = $guard->requireLogin();

    $tests['Authenticated identity'] =
        (int) $staff['company_id'] === 1
        && $staff['role'] === 'admin';

    // Test 4: Administrator has users.manage permission.
    $staff = $guard->requirePermission('users.manage');

    $tests['Administrator permission granted'] =
        (int) $staff['id'] === 1;

    // Test 5: Unknown permission is rejected.
    try {
        $guard->requirePermission('system.delete');
        $tests['Unknown permission rejected'] = false;
    } catch (RuntimeException $exception) {
        $tests['Unknown permission rejected'] =
            $exception->getCode() === 403;
    }

    // Test 6: Logout revokes authenticated access.
    $auth->logout();

    try {
        $guard->requireLogin();
        $tests['Logout revokes access'] = false;
    } catch (RuntimeException $exception) {
        $tests['Logout revokes access'] =
            $exception->getCode() === 401;
    }

    echo "FLEETIFY 2.0 - STAFF GUARD TEST\n";
    echo "--------------------------------\n";

    $passed = 0;

    foreach ($tests as $name => $result) {
        echo $name . ': '
            . ($result ? 'PASS' : 'FAIL')
            . PHP_EOL;

        if ($result) {
            $passed++;
        }
    }

    echo "--------------------------------\n";
    echo "RESULT: {$passed}/" . count($tests) . " tests passed\n";

    exit($passed === count($tests) ? 0 : 1);

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "ERROR: " . $exception->getMessage() . PHP_EOL
    );
    exit(1);
}
