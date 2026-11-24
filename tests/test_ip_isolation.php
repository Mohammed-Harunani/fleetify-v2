<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Auth\IpRateLimiter;

$limiter = new IpRateLimiter();

$blockedIp = '192.0.2.10';
$otherIp = '192.0.2.11';

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
    $limiter->clearFailures($blockedIp);
    $limiter->clearFailures($otherIp);

    for ($i = 0; $i < 20; $i++) {
        $limiter->recordFailure($blockedIp);
    }

    checkResult(
        'First IP locked',
        $limiter->isLocked($blockedIp)
    );

    checkResult(
        'Second IP remains unlocked',
        !$limiter->isLocked($otherIp)
    );

    $limiter->recordFailure($otherIp);

    checkResult(
        'Second IP accepts its own counter',
        !$limiter->isLocked($otherIp)
    );

    checkResult(
        'First IP remains locked',
        $limiter->isLocked($blockedIp)
    );

    echo "---------------------------------\n";
    echo "RESULT: {$passed}/{$total} tests passed\n";

    exit($passed === $total ? 0 : 1);
} finally {
    $limiter->clearFailures($blockedIp);
    $limiter->clearFailures($otherIp);
}