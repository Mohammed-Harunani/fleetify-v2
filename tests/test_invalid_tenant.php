<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Auth\AuthService;
use Fleetify\Core\Database;

$companyId = 999999;
$email = 'invalid-tenant-test@fleetify.test';

try {
    $pdo = Database::connection();

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM companies WHERE id = ?'
    );
    $statement->execute([$companyId]);

    if ((int) $statement->fetchColumn() !== 0) {
        throw new RuntimeException(
            'Test company ID already exists.'
        );
    }

    $auth = new AuthService();

    $result = $auth->login(
        $companyId,
        $email,
        'incorrect-test-password'
    );

    if ($result !== false) {
        throw new RuntimeException(
            'Invalid tenant login was not rejected.'
        );
    }

    echo "INVALID TENANT REJECTED: PASS\n";
    echo "NO DATABASE EXCEPTION: PASS\n";
    echo "RESULT: 2/2 tests passed\n";

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "TEST FAILED: " . $exception->getMessage() . PHP_EOL
    );
    exit(1);
}