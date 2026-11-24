<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Auth\AuthService;
use Fleetify\Auth\LoginRateLimiter;
use Fleetify\Core\Database;

$auth = new AuthService();
$limiter = new LoginRateLimiter();
$pdo = Database::connection();

$companyId = 1;
$email = 'auth-rate-limit-test@fleetify.test';

$passed = 0;
$total = 0;

function checkResult(string $name, bool $result): void
{
    global $passed, $total;

    $total++;

    if ($result) {
        $passed++;
    }

    echo $name . ': ' . ($result ? 'PASS' : 'FAIL') . PHP_EOL;
}

try {
    $limiter->clearFailures($companyId, $email);

    for ($i = 1; $i <= 5; $i++) {
        $result = $auth->login(
            $companyId,
            $email,
            'incorrect-test-password', '192.0.2.50'
        );

        checkResult(
            "Failed login {$i} rejected",
            $result === false
        );
    }

    checkResult(
        'Account locked after five failures',
        $limiter->isLocked($companyId, $email)
    );

    $statement = $pdo->prepare(
        'SELECT failed_attempts
         FROM staff_login_attempts
         WHERE company_id = :company_id
           AND email = :email'
    );

    $statement->execute([
        'company_id' => $companyId,
        'email' => $email,
    ]);

    checkResult(
        'Database counter equals five',
        (int) $statement->fetchColumn() === 5
    );

    // A locked account must reject further attempts.
    checkResult(
        'Additional login rejected during lockout',
        $auth->login(
            $companyId,
            $email,
            'another-incorrect-password', '192.0.2.50'
        ) === false
    );

    $statement->execute([
        'company_id' => $companyId,
        'email' => $email,
    ]);

    checkResult(
        'Locked attempts do not increase counter',
        (int) $statement->fetchColumn() === 5
    );

    echo "---------------------------------\n";
    echo "RESULT: {$passed}/{$total} tests passed\n";

    if ($passed !== $total) {
        exit(1);
    }
} finally {
    $limiter->clearFailures($companyId, $email);
}