<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Auth\LoginRateLimiter;
use Fleetify\Core\Database;

$limiter = new LoginRateLimiter();
$pdo = Database::connection();

$companyId = 1;
$email = 'rate-limit-test@fleetify.test';
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
    // Start with a clean test record.
    $limiter->clearFailures($companyId, $email);

    checkResult(
        'Initially unlocked',
        !$limiter->isLocked($companyId, $email)
    );

    // First four failures must not lock the account.
    for ($i = 1; $i <= 4; $i++) {
        $limiter->recordFailure($companyId, $email);

        checkResult(
            "Attempt {$i} remains unlocked",
            !$limiter->isLocked($companyId, $email)
        );
    }

    // Fifth failure must activate the lockout.
    $limiter->recordFailure($companyId, $email);

    checkResult(
        'Fifth attempt activates lockout',
        $limiter->isLocked($companyId, $email)
    );

    // Verify the database counter.
    $statement = $pdo->prepare(
        'SELECT failed_attempts, locked_until
         FROM staff_login_attempts
         WHERE company_id = :company_id
           AND email = :email'
    );

    $statement->execute([
        'company_id' => $companyId,
        'email' => $email,
    ]);

    $row = $statement->fetch();

    checkResult(
        'Database records five failures',
        $row !== false
        && (int) $row['failed_attempts'] === 5
        && $row['locked_until'] !== null
    );

    // Clear the test record.
    $limiter->clearFailures($companyId, $email);

    checkResult(
        'Clearing failures removes lockout',
        !$limiter->isLocked($companyId, $email)
    );

    echo "---------------------------------\n";
    echo "RESULT: {$passed}/{$total} tests passed\n";

    exit($passed === $total ? 0 : 1);

} finally {
    // Always remove the test account's attempt record.
    $limiter->clearFailures($companyId, $email);
}