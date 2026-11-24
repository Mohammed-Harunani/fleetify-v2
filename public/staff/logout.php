<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use Fleetify\Auth\AuthService;
use Fleetify\Helpers\Security;
use Fleetify\Core\Session;

Session::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$token = (string) ($_POST['csrf_token'] ?? '');

if (!Security::verifyCsrfToken($token)) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$auth = new AuthService();
$auth->logout();

header('Location: login.php', true, 303);
exit;
