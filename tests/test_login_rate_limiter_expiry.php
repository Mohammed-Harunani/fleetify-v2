<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Auth\LoginRateLimiter;
use Fleetify\Core\Database;

$limiter = new LoginRateLimiter();
$pdo = Database::connection();

$companyId = 1;
$email = 'rate-limit-expiry-test@fleetify.test';

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

$select = $pdo->prepare(
    'SELECT failed_attempts, locked_until
     FROM staff_login_attempts
     WHERE company_id = :company_id
       AND email = :email'
);

try {
    $limiter->clearFailures($companyId, $email);

    // Establish a genuine lockout.
    for ($i = 0; $i < 5; $i++) {
        $limiter->recordFailure($companyId, $email);
    }

    checkResult(
        'Initial lockout active',
        $limiter->isLocked($companyId, $email)
    );

    // Simulate the lockout having expired.
    $expire = $pdo->prepare(
        'UPDATE staff_login_attempts
         SET locked_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE),
             first_failed_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE)
         WHERE company_id = :company_id
           AND email = :email'
    );

    $expire->execute([
        'company_id' => $companyId,
        'email' => $email,
    ]);

    checkResult(
        'Expired lockout is inactive',
        !$limiter->isLocked($companyId, $email)
    );

    // A new failure should begin a fresh window.
    $limiter->recordFailure($companyId, $email);

    $select->execute([
        'company_id' => $companyId,
        'email' => $email,
    ]);

    $row = $select->fetch();

    checkResult(
        'Expired window resets counter to one',
        $row !== false
        && (int) $row['failed_attempts'] === 1
    );

    checkResult(
        'New window has no active lockout',
        !$limiter->isLocked($companyId, $email)
    );

    echo "---------------------------------\n";
    echo "RESULT: {$passed}/{$total} tests passed\n";

    exit($passed === $total ? 0 : 1);

} finally {
    $limiter->clearFailures($companyId, $email);
}