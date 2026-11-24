<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use Fleetify\Auth\AuthService;
use Fleetify\Helpers\Security;
use Fleetify\Core\Session;

Session::start();

$auth = new AuthService();

if ($auth->staffIdentity() !== null) {
    header('Location: dashboard.php', true, 302);
    exit;
}

$error = '';
$companyId = '1';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $companyId = trim((string) ($_POST['company_id'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!Security::verifyCsrfToken($token)) {
        $error = 'Invalid or expired form. Please try again.';
    } elseif (
        !ctype_digit($companyId)
        || (int) $companyId < 1
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || $password === ''
    ) {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;

        if (
            is_string($clientIp)
            && filter_var($clientIp, FILTER_VALIDATE_IP) !== false
        ) {
            (new \Fleetify\Auth\IpRateLimiter())
                ->recordFailure($clientIp);
        }

        $error = 'Please enter valid login details.';
    } elseif ($auth->login(
        (int) $companyId,
        $email,
        $password,
        $_SERVER['REMOTE_ADDR'] ?? null
    )) {
        header('Location: dashboard.php', true, 303);
        exit;
    } else {
        $error = 'Invalid credentials or inactive account.';
    }
}

$csrfToken = Security::csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login | Fleetify 2.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-slate-950 flex items-center justify-center p-6">

    <main class="w-full max-w-md">

        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-blue-600 text-white text-2xl font-bold mb-5">
                F
            </div>

            <h1 class="text-3xl font-bold text-white">
                Fleetify
            </h1>

            <p class="text-slate-400 mt-2">
                Staff Operations Portal
            </p>
        </div>

        <div class="bg-white rounded-2xl shadow-2xl p-8">

            <h2 class="text-xl font-bold text-slate-900 mb-2">
                Welcome back
            </h2>

            <p class="text-sm text-slate-500 mb-6">
                Sign in to your company workspace.
            </p>

            <?php if ($error !== ''): ?>
                <div
                    role="alert"
                    class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 mb-5 text-sm"
                >
                    <?= Security::escape($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="space-y-5">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= Security::escape($csrfToken) ?>"
                >

                <div>
                    <label for="company_id" class="block text-sm font-medium text-slate-700 mb-2">
                        Company ID
                    </label>

                    <input
                        id="company_id"
                        type="number"
                        name="company_id"
                        min="1"
                        required
                        value="<?= Security::escape($companyId) ?>"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700 mb-2">
                        Email address
                    </label>

                    <input
                        id="email"
                        type="email"
                        name="email"
                        autocomplete="username"
                        required
                        value="<?= Security::escape($email) ?>"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="you@company.com"
                    >
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700 mb-2">
                        Password
                    </label>

                    <input
                        id="password"
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Enter your password"
                    >
                </div>

                <button
                    type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition"
                >
                    Sign in
                </button>

            </form>
        </div>

        <p class="text-center text-slate-500 text-xs mt-6">
            Fleetify 2.0 &copy; <?= date('Y') ?>
        </p>

    </main>

</body>
</html>
