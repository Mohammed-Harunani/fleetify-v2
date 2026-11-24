<?php
declare(strict_types=1);

$secretPath = dirname(__DIR__, 3) . '/fleetify-v2-secrets/ip_hash_secret';

if (!is_file($secretPath)) {
    exit("FAIL: Secret file missing.\n");
}

$secret = file_get_contents($secretPath);

if ($secret === false) {
    exit("FAIL: Secret file unreadable.\n");
}

$key = base64_decode(trim($secret), true);

if ($key === false || strlen($key) !== 32) {
    exit("FAIL: Invalid secret format.\n");
}

echo "SECRET FILE EXISTS: PASS\n";
echo "SECRET FILE READABLE: PASS\n";
echo "SECRET KEY LENGTH VALID: PASS\n";
echo "RESULT: 3/3 tests passed\n";