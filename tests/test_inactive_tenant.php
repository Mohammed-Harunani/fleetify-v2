<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Auth\AuthService;
use Fleetify\Core\Database;

$pdo = Database::connection();
$auth = new AuthService();

$companyId = 3;
$email = 'inactive-tenant-test@fleetify.test';

$passed = 0;

try {
    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'SELECT status FROM companies WHERE id = ? FOR UPDATE'
    );
    $statement->execute([$companyId]);

    if ($statement->fetchColumn() === false) {
        throw new RuntimeException('Test company does not exist.');
    }

    $update = $pdo->prepare(
        'UPDATE companies SET status = ? WHERE id = ?'
    );

    foreach (['suspended', 'cancelled'] as $status) {
        $update->execute([$status, $companyId]);

        $result = $auth->login(
            $companyId,
            $email,
            'incorrect-test-password'
        );

        $success = ($result === false);

        echo strtoupper($status) . ' TENANT REJECTED: '
            . ($success ? 'PASS' : 'FAIL')
            . PHP_EOL;

        if ($success) {
            $passed++;
        }
    }

    echo "RESULT: {$passed}/2 tests passed\n";

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "TEST FAILED: " . $exception->getMessage() . PHP_EOL
    );
    exit(1);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

exit($passed === 2 ? 0 : 1);