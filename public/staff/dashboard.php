<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use Fleetify\Auth\StaffGuard;
use Fleetify\Auth\Authorization;
use Fleetify\Helpers\Security;
use Fleetify\Core\Session;

Session::start();
$guard = new StaffGuard();


try {
    $staff = $guard->requireLogin();
} catch (RuntimeException $exception) {
    header('Location: login.php', true, 302);
    exit;
}

$name = (string) $staff['name'];
$role = (string) $staff['role'];
$companyId = (int) $staff['company_id'];

$modules = [
    [
        'title' => 'Consignments',
        'description' => 'Manage shipments and deliveries.',
        'permission' => 'consignments.view',
    ],
    [
        'title' => 'Vehicles',
        'description' => 'Manage fleet vehicles.',
        'permission' => 'vehicles.manage',
    ],
    [
        'title' => 'Routes',
        'description' => 'Manage transport routes.',
        'permission' => 'routes.manage',
    ],
    [
        'title' => 'Offices',
        'description' => 'Manage company branches.',
        'permission' => 'offices.manage',
    ],
    [
        'title' => 'Users',
        'description' => 'Manage staff accounts.',
        'permission' => 'users.manage',
    ],
    [
        'title' => 'Reports',
        'description' => 'View operational reports.',
        'permission' => 'reports.view',
    ],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Fleetify 2.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-slate-100">

<header class="bg-slate-950 text-white shadow-lg">
    <div class="max-w-7xl mx-auto px-6 py-5 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold">Fleetify</h1>
            <p class="text-xs text-slate-400">Operations Workspace</p>
        </div>


<div class="flex items-center gap-6">
    <div class="text-right">
        <p class="font-semibold">
            <?= Security::escape($name) ?>
        </p>

        <p class="text-xs text-slate-400">
            <?= Security::escape(ucwords(str_replace('_', ' ', $role))) ?>
        </p>
    </div>

    <form method="POST" action="logout.php">
        <?= Security::csrfField() ?>

        <button
            type="submit"
            class="bg-red-600 hover:bg-red-700 text-white text-sm font-semibold px-4 py-2 rounded-lg transition"
        >
            Logout
        </button>
    </form>
</div>

    </div>
</header>

<main class="max-w-7xl mx-auto px-6 py-10">

    <div class="mb-10">
        <h2 class="text-3xl font-bold text-slate-900">
            Welcome, <?= Security::escape($name) ?>
        </h2>

        <p class="text-slate-500 mt-2">
            Company ID: <?= $companyId ?>
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

        <?php foreach ($modules as $module): ?>
            <?php if (Authorization::can($role, $module['permission'])): ?>

                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <h3 class="text-xl font-semibold text-slate-900">
                        <?= Security::escape($module['title']) ?>
                    </h3>

                    <p class="text-slate-500 mt-2 text-sm">
                        <?= Security::escape($module['description']) ?>
                    </p>

                    <span class="inline-block mt-6 text-sm font-medium text-blue-600">
                        Module coming soon
                    </span>
                </div>

            <?php endif; ?>
        <?php endforeach; ?>

    </div>

</main>

</body>
</html>
