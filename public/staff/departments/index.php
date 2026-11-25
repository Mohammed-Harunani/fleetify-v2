<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/config/bootstrap.php';

use Fleetify\Auth\StaffGuard;
use Fleetify\Core\Session;
use Fleetify\Helpers\Security;
use Fleetify\Modules\Departments\DepartmentRepository;
use Fleetify\Security\AccessControl;

Session::start();

// Authentication
try {
    $staff = (new StaffGuard())->requireLogin();
} catch (RuntimeException $exception) {
    header('Location: ../login.php', true, 302);
    exit;
}

// Database-backed permissions
$access = new AccessControl();

$canManage = $access->can('departments.manage', $staff);
$canView = $access->can('departments.view', $staff);
$canAssignStaff = $access->can('staff.assign', $staff);

if (!$canView && !$canManage) {
    http_response_code(403);
    exit('Access denied.');
}

// Company-scoped department data
$companyId = (int) $staff['company_id'];

$departments = (new DepartmentRepository())
    ->listForCompany($companyId);

// Dashboard statistics
$totalDepartments = count($departments);

$activeDepartments = count(array_filter(
    $departments,
    static fn(array $department): bool =>
        (int) $department['is_enabled'] === 1
));

$inactiveDepartments = $totalDepartments - $activeDepartments;

// One-time success notification
$flash = Session::get('department_flash');
Session::remove('department_flash');

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Departments | Fleetify 2.0</title>

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-slate-100 text-slate-900">

<!-- HEADER -->
<header class="bg-slate-950 text-white shadow-lg">

    <div class="max-w-7xl mx-auto px-6 py-5
                flex flex-wrap justify-between items-center gap-4">

        <div>
            <h1 class="text-2xl font-bold">Fleetify</h1>

            <p class="text-xs text-slate-400">
                Department Management
            </p>
        </div>

        <a
            href="../dashboard.php"
            class="text-sm text-slate-300 hover:text-white transition"
        >
            Back to Main Dashboard
        </a>

    </div>

</header>

<!-- MAIN -->
<main class="max-w-7xl mx-auto px-6 py-10">

    <!-- WORKSPACE NAVIGATION -->
    <nav
        aria-label="Department management navigation"
        class="flex flex-wrap gap-3 mb-8"
    >

        <a
            href="index.php"
            aria-current="page"
            class="bg-blue-600 text-white px-5 py-3
                   rounded-xl text-sm font-semibold shadow-sm"
        >
            Departments
        </a>

        <?php if ($canAssignStaff): ?>

            <a
                href="staff.php"
                class="bg-white text-slate-700
                       border border-slate-200
                       hover:border-blue-400 hover:text-blue-600
                       px-5 py-3 rounded-xl
                       text-sm font-semibold transition"
            >
                Staff Assignments
            </a>

            <a
                href="branches.php"
                class="bg-white text-slate-700
                       border border-slate-200
                       hover:border-blue-400 hover:text-blue-600
                       px-5 py-3 rounded-xl
                       text-sm font-semibold transition"
            >
                Branch Access
            </a>

        <?php endif; ?>

    </nav>

    <!-- SUCCESS NOTIFICATION -->
    <?php if (is_string($flash) && $flash !== ''): ?>

        <div
            role="status"
            class="mb-6 rounded-xl border border-emerald-200
                   bg-emerald-50 px-5 py-4
                   text-sm font-medium text-emerald-800"
        >
            <?= Security::escape($flash) ?>
        </div>

    <?php endif; ?>

    <!-- PAGE HEADING -->
    <section class="mb-8">

        <p class="text-sm font-semibold text-blue-600 mb-2">
            ORGANISATION MANAGEMENT
        </p>

        <h2 class="text-3xl font-bold">
            Departments
        </h2>

        <p class="text-slate-500 mt-2">
            Manage your company's departments and operational structure.
        </p>

    </section>

    <!-- SUMMARY CARDS -->
    <section class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">

        <div class="bg-white rounded-2xl border border-slate-200
                    p-6 shadow-sm">

            <p class="text-sm text-slate-500">
                Total Departments
            </p>

            <p class="text-4xl font-bold mt-3">
                <?= $totalDepartments ?>
            </p>

        </div>

        <div class="bg-white rounded-2xl border border-slate-200
                    p-6 shadow-sm">

            <p class="text-sm text-slate-500">
                Active Departments
            </p>

            <p class="text-4xl font-bold mt-3 text-emerald-600">
                <?= $activeDepartments ?>
            </p>

        </div>

        <div class="bg-white rounded-2xl border border-slate-200
                    p-6 shadow-sm">

            <p class="text-sm text-slate-500">
                Inactive Departments
            </p>

            <p class="text-4xl font-bold mt-3 text-amber-600">
                <?= $inactiveDepartments ?>
            </p>

        </div>

    </section>

    <!-- DEPARTMENT DIRECTORY -->
    <section class="bg-white rounded-2xl
                    border border-slate-200
                    shadow-sm overflow-hidden">

        <div class="p-6 border-b border-slate-200
                    flex flex-col md:flex-row
                    md:items-center md:justify-between gap-4">

            <div>
                <h3 class="text-lg font-bold">
                    Department Directory
                </h3>

                <p class="text-sm text-slate-500 mt-1">
                    Departments registered under your company.
                </p>
            </div>

            <input
                id="departmentSearch"
                type="search"
                placeholder="Search departments..."
                aria-label="Search departments"
                class="w-full md:w-72 rounded-lg
                       border border-slate-300 px-4 py-2.5
                       text-sm focus:ring-2 focus:ring-blue-500
                       outline-none"
            >

        </div>

        <div class="overflow-x-auto">

            <table class="w-full text-left text-sm">

                <thead class="bg-slate-50 text-slate-500
                              uppercase text-xs">

                    <tr>
                        <th class="px-6 py-4">Department</th>
                        <th class="px-6 py-4">Code</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4 text-right">Management</th>
                    </tr>

                </thead>

                <tbody class="divide-y divide-slate-100">

                    <?php foreach ($departments as $department): ?>

                        <?php
                        $enabled =
                            (int) $department['is_enabled'] === 1;
                        ?>

                        <tr class="department-row hover:bg-slate-50 transition">

                            <!-- DEPARTMENT -->
                            <td class="px-6 py-5 font-semibold">

                                <?= Security::escape(
                                    (string) $department['name']
                                ) ?>

                            </td>

                            <!-- CODE -->
                            <td class="px-6 py-5 text-slate-500">

                                <?= Security::escape(
                                    (string) $department['code']
                                ) ?>

                            </td>

                            <!-- STATUS -->
                            <td class="px-6 py-5">

                                <?php if ($enabled): ?>

                                    <span
                                        class="inline-flex rounded-full
                                               bg-emerald-50 text-emerald-700
                                               px-3 py-1 text-xs font-semibold"
                                    >
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span
                                        class="inline-flex rounded-full
                                               bg-amber-50 text-amber-700
                                               px-3 py-1 text-xs font-semibold"
                                    >
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>

                            <!-- MANAGEMENT -->
                            <td class="px-6 py-5 text-right">

                                <?php if ($canManage): ?>

                                    <form
                                        method="POST"
                                        action="update.php"
                                        class="inline-block"
                                    >

                                        <?= Security::csrfField() ?>

                                        <input
                                            type="hidden"
                                            name="department_id"
                                            value="<?= (int) $department['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="enabled"
                                            value="<?= $enabled ? '0' : '1' ?>"
                                        >

                                        <button
                                            type="submit"
                                            onclick="return confirm('Are you sure you want to change this department status?')"
                                            class="<?= $enabled
                                                ? 'bg-amber-50 text-amber-700 hover:bg-amber-100'
                                                : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100'
                                            ?> px-4 py-2 rounded-lg
                                               text-xs font-semibold transition"
                                        >
                                            <?= $enabled
                                                ? 'Deactivate'
                                                : 'Activate'
                                            ?>
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span class="text-slate-400">
                                        View only
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

        <?php if ($totalDepartments === 0): ?>

            <div class="p-8 text-center text-slate-500">
                No departments configured.
            </div>

        <?php endif; ?>

        <div
            id="noSearchResults"
            class="hidden p-8 text-center text-slate-500"
        >
            No matching departments found.
        </div>

    </section>

    <!-- FOOTER -->
    <footer class="mt-10 pt-6 border-t border-slate-200">

        <p class="text-xs text-slate-400">
            Fleetify 2.0 &copy; <?= date('Y') ?>
            &middot; Department Management
            &middot; Company <?= $companyId ?>
        </p>

    </footer>

</main>

<!-- DEPARTMENT SEARCH -->
<script>
const searchInput = document.getElementById('departmentSearch');
const rows = document.querySelectorAll('.department-row');
const emptyMessage = document.getElementById('noSearchResults');

searchInput.addEventListener('input', () => {
    const query = searchInput.value.trim().toLowerCase();
    let visible = 0;

    rows.forEach(row => {
        const matches = row.textContent.toLowerCase().includes(query);

        row.classList.toggle('hidden', !matches);

        if (matches) {
            visible++;
        }
    });

    emptyMessage.classList.toggle(
        'hidden',
        visible !== 0 || rows.length === 0
    );
});
</script>

</body>
</html>
