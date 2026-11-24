<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Auth\IpRateLimiter;
use Fleetify\Core\Database;

$limiter = new IpRateLimiter();
$pdo = Database::connection();

$ip = '192.0.2.10';
$passed = 0;
$total = 0;

function checkResult(string $name, bool $result): void
{
    global $passed, $total;

    $total++;

    if ($result) {
        $passed++;
    }

    echo $name . ': '
        . ($result ? 'PASS' : 'FAIL')
        . PHP_EOL;
}

try {
    $limiter->clearFailures($ip);

    checkResult(
        'Initially unlocked',
        !$limiter->isLocked($ip)
    );

    for ($i = 1; $i <= 19; $i++) {
        $limiter->recordFailure($ip);
    }

    checkResult(
        'Nineteen failures remain unlocked',
        !$limiter->isLocked($ip)
    );

    $limiter->recordFailure($ip);

    checkResult(
        'Twentieth failure activates lockout',
        $limiter->isLocked($ip)
    );

    // The test record is the only one for this IP.
    // Check the stored counter without exposing its hash.
    $statement = $pdo->query(
        'SELECT COUNT(*)
         FROM staff_ip_login_attempts
         WHERE failed_attempts = 20
           AND locked_until > NOW()'
    );

    checkResult(
        'Active 20-attempt record exists',
        (int) $statement->fetchColumn() >= 1
    );

    $limiter->recordFailure($ip);

    checkResult(
        'Further failures remain blocked',
        $limiter->isLocked($ip)
    );

    $limiter->clearFailures($ip);

    checkResult(
        'Clearing failures removes lockout',
        !$limiter->isLocked($ip)
    );

    echo "---------------------------------\n";
    echo "RESULT: {$passed}/{$total} tests passed\n";

    exit($passed === $total ? 0 : 1);
} finally {
    $limiter->clearFailures($ip);
}