<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use Fleetify\Auth\StaffGuard;
use Fleetify\Auth\Authorization;
use Fleetify\Core\Session;
use Fleetify\Helpers\Security;
use Fleetify\Security\AccessControl;

Session::start();

try {
    $staff = (new StaffGuard())->requireLogin();
} catch (RuntimeException $exception) {
    header('Location: login.php', true, 302);
    exit;
}

$name = (string) $staff['name'];
$role = (string) $staff['role'];
$companyId = (int) $staff['company_id'];

$access = new AccessControl();

// Existing Fleetify modules.
$modules = [
    [
        'title' => 'Consignments',
        'description' => 'Manage shipments and deliveries.',
        'permission' => 'consignments.view',
        'icon' => '📦',
    ],
    [
        'title' => 'Vehicles',
        'description' => 'Manage fleet vehicles and maintenance.',
        'permission' => 'vehicles.manage',
        'icon' => '🚛',
    ],
    [
        'title' => 'Routes',
        'description' => 'Manage transport routes and dispatch.',
        'permission' => 'routes.manage',
        'icon' => '🗺️',
    ],
    [
        'title' => 'Offices',
        'description' => 'Manage company branches and offices.',
        'permission' => 'offices.manage',
        'icon' => '🏢',
    ],
    [
        'title' => 'Users',
        'description' => 'Manage staff accounts and access.',
        'permission' => 'users.manage',
        'icon' => '👥',
    ],
    [
        'title' => 'Reports',
        'description' => 'View operational reports and analytics.',
        'permission' => 'reports.view',
        'icon' => '📊',
    ],
];

$canViewFleet = $access->can('fleet.view', $staff) || $access->can('vehicles.manage', $staff);

// Department Management uses the database-backed permissions
// introduced in Phase 24.
$canViewDepartments =
    $access->can('departments.view', $staff)
    || $access->can('departments.manage', $staff);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard | Fleetify 2.0</title>

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-slate-100 text-slate-900">

<!-- HEADER -->
<header class="bg-slate-950 text-white shadow-lg">

    <div class="max-w-7xl mx-auto px-6 py-5 flex justify-between items-center gap-4">

        <div>
            <h1 class="text-2xl font-bold">Fleetify</h1>
            <p class="text-xs text-slate-400">
                Operations Workspace
            </p>
        </div>

        <div class="flex items-center gap-6">

            <div class="text-right hidden sm:block">

                <p class="font-semibold">
                    <?= Security::escape($name) ?>
                </p>

                <p class="text-xs text-slate-400">
                    <?= Security::escape(
                        ucwords(str_replace('_', ' ', $role))
                    ) ?>
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

<!-- MAIN CONTENT -->
<main class="max-w-7xl mx-auto px-6 py-10">

    <!-- WELCOME -->
    <section class="mb-10">

        <p class="text-sm font-semibold text-blue-600 mb-2">
            STAFF OPERATIONS PORTAL
        </p>

        <h2 class="text-3xl font-bold text-slate-900">
            Welcome, <?= Security::escape($name) ?>
        </h2>

        <p class="text-slate-500 mt-2">
            Manage your company's transport operations,
            departments and staff activities.
        </p>

        <div class="mt-5 inline-flex items-center gap-2
                    bg-white border border-slate-200
                    rounded-lg px-4 py-2 shadow-sm">

            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>

            <span class="text-sm text-slate-600">
                Company ID:
                <strong><?= $companyId ?></strong>
            </span>

        </div>

    </section>

    <!-- SECTION TITLE -->
    <div class="mb-6">

        <h3 class="text-xl font-bold text-slate-900">
            Management Modules
        </h3>

        <p class="text-sm text-slate-500 mt-1">
            Select a module to access its workspace.
        </p>

    </div>

    <!-- MODULE GRID -->
    <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

        <?php if ($canViewFleet): ?>
        <a href="fleet/index.php" class="group block bg-white rounded-2xl border border-blue-200 shadow-sm p-6 hover:shadow-lg hover:border-blue-400 transition">
          <div class="text-3xl">🚛</div><h4 class="text-xl font-semibold mt-5">Fleet & Workshop Management</h4>
          <p class="text-slate-500 mt-2 text-sm">Vehicles, drivers, odometer, maintenance and safety.</p>
          <p class="mt-6 text-blue-700 font-semibold text-sm">Open Fleet Workspace &rarr;</p>
        </a>
        <?php endif; ?>

        <!-- DEPARTMENT MANAGEMENT -->
        <?php if ($canViewDepartments): ?>

            <a
                href="departments/index.php"
                class="group block bg-white rounded-2xl
                       border border-blue-200 shadow-sm p-6
                       hover:shadow-lg hover:border-blue-400
                       transition duration-200"
            >

                <div class="flex justify-between items-start">

                    <div class="w-12 h-12 rounded-xl bg-blue-50
                                flex items-center justify-center text-2xl">
                        🏢
                    </div>

                    <span class="bg-emerald-50 text-emerald-700
                                 text-xs font-semibold px-3 py-1
                                 rounded-full">
                        Available
                    </span>

                </div>

                <h4 class="text-xl font-semibold text-slate-900 mt-5
                           group-hover:text-blue-600 transition">
                    Department Management
                </h4>

                <p class="text-slate-500 mt-2 text-sm leading-relaxed">
                    Manage departments, operational structure,
                    and department availability.
                </p>

                <div class="mt-6 flex items-center justify-between">

                    <span class="text-sm font-semibold text-blue-600">
                        Open Department Workspace
                    </span>

                    <span class="text-blue-600 text-xl
                                 group-hover:translate-x-1 transition">
                        &rarr;
                    </span>

                </div>

            </a>

        <?php endif; ?>

        <!-- EXISTING MODULES -->
        <?php foreach ($modules as $module): ?>

            <?php if (Authorization::can($role, $module['permission'])): ?>

                <div class="bg-white rounded-2xl
                            border border-slate-200
                            shadow-sm p-6">

                    <div class="flex justify-between items-start">

                        <div class="w-12 h-12 rounded-xl bg-slate-100
                                    flex items-center justify-center text-2xl">
                            <?= Security::escape($module['icon']) ?>
                        </div>

                        <span class="bg-slate-100 text-slate-500
                                     text-xs font-semibold px-3 py-1
                                     rounded-full">
                            Planned
                        </span>

                    </div>

                    <h4 class="text-xl font-semibold text-slate-900 mt-5">
                        <?= Security::escape($module['title']) ?>
                    </h4>

                    <p class="text-slate-500 mt-2 text-sm leading-relaxed">
                        <?= Security::escape($module['description']) ?>
                    </p>

                    <div class="mt-6">

                        <span class="text-sm font-medium text-slate-400">
                            Module coming soon
                        </span>

                    </div>

                </div>

            <?php endif; ?>

        <?php endforeach; ?>

    </section>

    <!-- FOOTER -->
    <footer class="mt-12 pt-6 border-t border-slate-200
                   flex flex-col sm:flex-row justify-between gap-3">

        <p class="text-xs text-slate-400">
            Fleetify 2.0 &copy; <?= date('Y') ?>
        </p>

        <p class="text-xs text-slate-400">
            Secure Staff Operations Workspace
        </p>

    </footer>

</main>

</body>
</html>
