<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/config/bootstrap.php';

use Fleetify\Auth\StaffGuard;
use Fleetify\Core\Database;
use Fleetify\Core\Session;
use Fleetify\Helpers\Security;
use Fleetify\Security\AccessControl;

Session::start();

// --------------------------------------------------
// AUTHENTICATION
// --------------------------------------------------

try {
    $staff = (new StaffGuard())->requireLogin();
} catch (RuntimeException $exception) {
    header('Location: ../login.php', true, 302);
    exit;
}

// --------------------------------------------------
// AUTHORISATION
// --------------------------------------------------

$access = new AccessControl();

if (!$access->can('staff.assign', $staff)) {
    http_response_code(403);
    exit('Access denied.');
}

$canViewDepartments =
    $access->can('departments.view', $staff)
    || $access->can('departments.manage', $staff);

$companyId = (int) $staff['company_id'];
$db = Database::connection();

// --------------------------------------------------
// ACTIVE STAFF
// --------------------------------------------------

$query = $db->prepare(
    "SELECT id, name, email, role
     FROM users
     WHERE company_id = :company
       AND status = 'active'
     ORDER BY name"
);

$query->execute(['company' => $companyId]);

$staffMembers = $query->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// ACTIVE COMPANY BRANCHES
// --------------------------------------------------

$query = $db->prepare(
    "SELECT id, name
     FROM offices
     WHERE company_id = :company
       AND status = 'active'
     ORDER BY name"
);

$query->execute(['company' => $companyId]);

$offices = $query->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// EXISTING BRANCH GRANTS
// --------------------------------------------------

$query = $db->prepare(
    "SELECT
        sba.user_id,
        sba.office_id,
        o.name AS office_name
     FROM staff_branch_access sba

     INNER JOIN offices o
        ON o.id = sba.office_id
       AND o.company_id = sba.company_id

     INNER JOIN users u
        ON u.id = sba.user_id
       AND u.company_id = sba.company_id

     WHERE sba.company_id = :company
       AND u.status = 'active'

     ORDER BY sba.user_id, o.name"
);

$query->execute(['company' => $companyId]);

$branchesByStaff = [];

foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $branch) {
    $userId = (int) $branch['user_id'];

    $branchesByStaff[$userId][] = [
        'office_id' => (int) $branch['office_id'],
        'office_name' => (string) $branch['office_name']
    ];
}

// --------------------------------------------------
// DASHBOARD STATISTICS
// --------------------------------------------------

$totalStaff = count($staffMembers);
$totalBranches = count($offices);

$totalGrants = array_sum(
    array_map('count', $branchesByStaff)
);

// --------------------------------------------------
// SUCCESS NOTIFICATION
// --------------------------------------------------

$flash = Session::get('branch_access_flash');
Session::remove('branch_access_flash');

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Branch Access | Fleetify 2.0</title>

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-slate-100 text-slate-900">

<!-- HEADER -->
<header class="bg-slate-950 text-white shadow-lg">

    <div class="max-w-7xl mx-auto px-6 py-5
                flex flex-wrap justify-between items-center gap-4">

        <div>
            <h1 class="text-2xl font-bold">
                Fleetify
            </h1>

            <p class="text-xs text-slate-400">
                Branch Access Management
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

<!-- MAIN CONTENT -->
<main class="max-w-7xl mx-auto px-6 py-10">

    <!-- WORKSPACE NAVIGATION -->
    <nav
        aria-label="Department management navigation"
        class="flex flex-wrap gap-3 mb-8"
    >

        <?php if ($canViewDepartments): ?>

            <a
                href="index.php"
                class="bg-white text-slate-700
                       border border-slate-200
                       hover:border-blue-400 hover:text-blue-600
                       px-5 py-3 rounded-xl
                       text-sm font-semibold transition"
            >
                Departments
            </a>

        <?php endif; ?>

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
            aria-current="page"
            class="bg-blue-600 text-white
                   px-5 py-3 rounded-xl
                   text-sm font-semibold shadow-sm"
        >
            Branch Access
        </a>

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
            Branch Access Management
        </h2>

        <p class="text-slate-500 mt-2">
            Control employee access to company branches and offices.
        </p>

    </section>

    <!-- SUMMARY CARDS -->
    <section class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">

        <!-- ACTIVE STAFF -->
        <div class="bg-white rounded-2xl
                    border border-slate-200 p-6 shadow-sm">

            <p class="text-sm text-slate-500">
                Active Staff
            </p>

            <p class="text-4xl font-bold mt-3">
                <?= $totalStaff ?>
            </p>

        </div>

        <!-- ACTIVE BRANCHES -->
        <div class="bg-white rounded-2xl
                    border border-slate-200 p-6 shadow-sm">

            <p class="text-sm text-slate-500">
                Active Branches
            </p>

            <p class="text-4xl font-bold mt-3 text-blue-600">
                <?= $totalBranches ?>
            </p>

        </div>

        <!-- RECORDED GRANTS -->
        <div class="bg-white rounded-2xl
                    border border-slate-200 p-6 shadow-sm">

            <p class="text-sm text-slate-500">
                Recorded Access Grants
            </p>

            <p class="text-4xl font-bold mt-3 text-emerald-600">
                <?= $totalGrants ?>
            </p>

        </div>

    </section>

    <!-- MANAGEMENT GRID -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- STAFF BRANCH DIRECTORY -->
        <section class="lg:col-span-2 bg-white rounded-2xl
                        border border-slate-200
                        shadow-sm overflow-hidden">

            <div class="p-6 border-b border-slate-200">

                <h3 class="text-lg font-bold">
                    Staff Branch Directory
                </h3>

                <p class="text-sm text-slate-500 mt-1">
                    Explicit branch access grants for active staff.
                </p>

            </div>

            <div class="overflow-x-auto">

                <table class="w-full text-left text-sm">

                    <thead class="bg-slate-50 text-slate-500
                                  text-xs uppercase">

                        <tr>
                            <th class="px-6 py-4">
                                Staff Member
                            </th>

                            <th class="px-6 py-4">
                                Role
                            </th>

                            <th class="px-6 py-4">
                                Granted Branches
                            </th>
                        </tr>

                    </thead>

                    <tbody class="divide-y divide-slate-100">

                        <?php foreach ($staffMembers as $member): ?>

                            <?php
                            $memberId = (int) $member['id'];

                            $grants = $branchesByStaff[$memberId] ?? [];
                            ?>

                            <tr class="hover:bg-slate-50 transition">

                                <!-- STAFF DETAILS -->
                                <td class="px-6 py-4">

                                    <p class="font-semibold">
                                        <?= Security::escape(
                                            (string) $member['name']
                                        ) ?>
                                    </p>

                                    <p class="text-xs text-slate-500 mt-1">
                                        <?= Security::escape(
                                            (string) $member['email']
                                        ) ?>
                                    </p>

                                </td>

                                <!-- ROLE -->
                                <td class="px-6 py-4 text-slate-500">

                                    <?= Security::escape(
                                        (string) $member['role']
                                    ) ?>

                                </td>

                                <!-- BRANCH GRANTS -->
                                <td class="px-6 py-4">

                                    <div class="flex flex-wrap gap-2">

                                        <?php if (!$grants): ?>

                                            <span class="text-xs text-slate-400">
                                                No explicit grants
                                            </span>

                                        <?php endif; ?>

                                        <?php foreach ($grants as $grant): ?>

                                            <div class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5">
                                                <span class="text-xs font-medium text-blue-700">
                                                    <?= Security::escape($grant['office_name']) ?>
                                                </span>

                                                <form
                                                    method="POST"
                                                    action="revoke.php"
                                                    onsubmit="return confirm('Revoke access to ' + <?= json_encode($grant['office_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + ' for ' + <?= json_encode((string) $member['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + '?');"
                                                >
                                                    <?= Security::csrfField() ?>
                                                    <input type="hidden" name="staff_id" value="<?= $memberId ?>">
                                                    <input type="hidden" name="office_id" value="<?= (int) $grant['office_id'] ?>">
                                                    <button
                                                        type="submit"
                                                        class="rounded-md border border-red-200 bg-white px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500"
                                                        aria-label="Revoke <?= Security::escape($grant['office_name']) ?> access for <?= Security::escape((string) $member['name']) ?>"
                                                    >
                                                        Revoke
                                                    </button>
                                                </form>
                                            </div>

                                        <?php endforeach; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <?php if (!$staffMembers): ?>

                <div class="p-8 text-center text-sm text-slate-500">
                    No active staff members found.
                </div>

            <?php endif; ?>

        </section>

        <!-- GRANT ACCESS FORM -->
        <section class="bg-white rounded-2xl
                        border border-slate-200
                        shadow-sm p-6">

            <h3 class="text-lg font-bold">
                Grant Branch Access
            </h3>

            <p class="text-sm text-slate-500 mt-2 mb-6">
                Select an employee and an active company branch.
            </p>

            <form
                method="POST"
                action="grant.php"
                class="space-y-5"
            >

                <?= Security::csrfField() ?>

                <!-- STAFF SELECTION -->
                <div>

                    <label
                        for="staff_id"
                        class="block text-sm font-medium mb-2"
                    >
                        Staff Member
                    </label>

                    <select
                        id="staff_id"
                        name="staff_id"
                        required
                        class="w-full border border-slate-300
                               rounded-lg px-3 py-3
                               focus:outline-none focus:ring-2
                               focus:ring-blue-500"
                    >

                        <option value="">
                            Select staff member
                        </option>

                        <?php foreach ($staffMembers as $member): ?>

                            <option value="<?= (int) $member['id'] ?>">
                                <?= Security::escape(
                                    (string) $member['name']
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <!-- BRANCH SELECTION -->
                <div>

                    <label
                        for="office_id"
                        class="block text-sm font-medium mb-2"
                    >
                        Branch / Office
                    </label>

                    <select
                        id="office_id"
                        name="office_id"
                        required
                        class="w-full border border-slate-300
                               rounded-lg px-3 py-3
                               focus:outline-none focus:ring-2
                               focus:ring-blue-500"
                    >

                        <option value="">
                            Select branch
                        </option>

                        <?php foreach ($offices as $office): ?>

                            <option value="<?= (int) $office['id'] ?>">
                                <?= Security::escape(
                                    (string) $office['name']
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <!-- SUBMIT BUTTON -->
                <button
                    type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700
                           text-white font-semibold py-3
                           rounded-lg transition
                           disabled:opacity-50
                           disabled:cursor-not-allowed"
                    <?= (!$staffMembers || !$offices) ? 'disabled' : '' ?>
                >
                    Grant Branch Access
                </button>

            </form>

            <!-- SECURITY INFORMATION -->
            <div class="mt-6 pt-5 border-t border-slate-100">

                <p class="text-xs text-slate-400 leading-relaxed">
                    Branch grants are restricted to the authenticated
                    company. Effective branch access also depends on
                    the employee's role and permissions.
                </p>

            </div>

        </section>

    </div>

    <!-- FOOTER -->
    <footer class="mt-10 pt-6 border-t border-slate-200">

        <p class="text-xs text-slate-400">
            Fleetify 2.0 &copy; <?= date('Y') ?>
            &middot; Branch Access Management
            &middot; Company <?= $companyId ?>
        </p>

    </footer>

</main>

</body>
</html>
