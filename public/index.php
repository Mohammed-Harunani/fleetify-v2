<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Fleetify\Auth\AuthService;

$auth = new AuthService();

if ($auth->staffIdentity() !== null) {
    header('Location: staff/dashboard.php', true, 302);
    exit;
}

header('Location: staff/login.php', true, 302);
exit;
