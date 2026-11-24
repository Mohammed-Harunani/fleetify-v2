<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Auth\IpRateLimiter;
use Fleetify\Core\Database;

$limiter = new IpRateLimiter();
$pdo = Database::connection();
$ip = '192.0.2.12';

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
    $limiter->clearFailures($ip);

    for ($i = 0; $i < 20; $i++) {
        $limiter->recordFailure($ip);
    }

    checkResult(
        'Lockout initially active',
        $limiter->isLocked($ip)
    );

    // Locate the test record without printing the IP hash.
    // This IP has exactly one associated row.
    $secretPath = dirname(__DIR__, 3) . '/fleetify-v2-secrets/ip_hash_secret';
    $secret = base64_decode(trim(file_get_contents($secretPath)), true);
    $hash = hash_hmac('sha256', inet_pton($ip), $secret);

    $expire = $pdo->prepare(
        'UPDATE staff_ip_login_attempts
         SET locked_until = DATE_SUB(NOW(), INTERVAL 1 SECOND),
             first_failed_at = DATE_SUB(NOW(), INTERVAL 31 MINUTE)
         WHERE ip_hash = :ip_hash'
    );

    $expire->execute(['ip_hash' => $hash]);

    checkResult(
        'Expired lockout no longer blocks IP',
        !$limiter->isLocked($ip)
    );

    $limiter->recordFailure($ip);

    $read = $pdo->prepare(
        'SELECT failed_attempts, locked_until
         FROM staff_ip_login_attempts
         WHERE ip_hash = :ip_hash'
    );

    $read->execute(['ip_hash' => $hash]);
    $row = $read->fetch(PDO::FETCH_ASSOC);

    checkResult(
        'New window starts at one failure',
        $row !== false
        && (int) $row['failed_attempts'] === 1
    );

    checkResult(
        'New window has no lockout',
        $row !== false
        && $row['locked_until'] === null
        && !$limiter->isLocked($ip)
    );

    echo "---------------------------------\n";
    echo "RESULT: {$passed}/{$total} tests passed\n";

    exit($passed === $total ? 0 : 1);
} finally {
    $limiter->clearFailures($ip);
}