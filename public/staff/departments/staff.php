<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/config/bootstrap.php';

use Fleetify\Auth\StaffGuard;
use Fleetify\Core\Database;
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

// Permission checks
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

// Active company staff
$query = $db->prepare(
    "SELECT id, name, email, role
     FROM users
     WHERE company_id = :company
       AND status = 'active'
     ORDER BY name"
);

$query->execute(['company' => $companyId]);
$staffMembers = $query->fetchAll(PDO::FETCH_ASSOC);

// Enabled company departments
$departments = array_values(array_filter(
    (new DepartmentRepository())->listForCompany($companyId),
    static fn(array $department): bool =>
        (int) $department['is_enabled'] === 1
));

// Current department assignments
$assignmentQuery = $db->prepare(
    "SELECT
        sd.user_id,
        d.name AS department_name,
        d.code AS department_code,
        sd.is_primary
     FROM staff_departments sd
     INNER JOIN company_departments d
        ON d.id = sd.department_id
       AND d.company_id = sd.company_id
     INNER JOIN users u
        ON u.id = sd.user_id
       AND u.company_id = sd.company_id
     WHERE sd.company_id = :company
       AND u.status = 'active'
     ORDER BY sd.user_id, sd.is_primary DESC, d.name"
);

$assignmentQuery->execute(['company' => $companyId]);

$assignmentsByStaff = [];

foreach ($assignmentQuery->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
    $userId = (int) $assignment['user_id'];

    $assignmentsByStaff[$userId][] = [
        'name' => (string) $assignment['department_name'],
        'code' => (string) $assignment['department_code'],
        'primary' => (int) $assignment['is_primary'] === 1,
    ];
}

// One-time success notification
$flash = Session::get('staff_assignment_flash');
Session::remove('staff_assignment_flash');

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Staff Assignments | Fleetify 2.0</title>

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-slate-100 text-slate-900">

<header class="bg-slate-950 text-white shadow-lg">
    <div class="max-w-7xl mx-auto px-6 py-5 flex flex-wrap justify-between items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold">Fleetify</h1>
            <p class="text-xs text-slate-400">Staff Management</p>
        </div>

        <a href="../dashboard.php"
           class="text-sm text-slate-300 hover:text-white transition">
            Back to Main Dashboard
        </a>
    </div>
</header>

<main class="max-w-7xl mx-auto px-6 py-10">

    <!-- WORKSPACE NAVIGATION -->
    <nav aria-label="Department management navigation"
         class="flex flex-wrap gap-3 mb-8">

        <?php if ($canViewDepartments): ?>
            <a href="index.php"
               class="bg-white text-slate-700 border border-slate-200
                      hover:border-blue-400 hover:text-blue-600
                      px-5 py-3 rounded-xl text-sm font-semibold transition">
                Departments
            </a>
        <?php endif; ?>

        <a href="staff.php"
           aria-current="page"
           class="bg-blue-600 text-white px-5 py-3 rounded-xl
                  text-sm font-semibold shadow-sm">
            Staff Assignments
        </a>

        <a href="branches.php"
           class="bg-white text-slate-700 border border-slate-200
                  hover:border-blue-400 hover:text-blue-600
                  px-5 py-3 rounded-xl text-sm font-semibold transition">
            Branch Access
        </a>

    </nav>

    <!-- SUCCESS MESSAGE -->
    <?php if (is_string($flash) && $flash !== ''): ?>
        <div role="status"
             class="mb-6 rounded-xl border border-emerald-200
                    bg-emerald-50 px-5 py-4 text-sm font-medium
                    text-emerald-800">
            <?= Security::escape($flash) ?>
        </div>
    <?php endif; ?>

    <!-- HEADING -->
    <section class="mb-8">
        <p class="text-sm font-semibold text-blue-600 mb-2">
            ORGANISATION MANAGEMENT
        </p>

        <h2 class="text-3xl font-bold">
            Staff Department Assignments
        </h2>

        <p class="text-slate-500 mt-2">
            Assign employees to primary and secondary departments
            within your organisation.
        </p>
    </section>

    <!-- SUMMARY -->
    <section class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">

        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
            <p class="text-sm text-slate-500">Active Staff</p>
            <p class="text-4xl font-bold mt-3">
                <?= count($staffMembers) ?>
            </p>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
            <p class="text-sm text-slate-500">Enabled Departments</p>
            <p class="text-4xl font-bold mt-3 text-blue-600">
                <?= count($departments) ?>
            </p>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
            <p class="text-sm text-slate-500">Staff With Assignments</p>
            <p class="text-4xl font-bold mt-3 text-emerald-600">
                <?= count($assignmentsByStaff) ?>
            </p>
        </div>

    </section>

    <!-- STAFF DIRECTORY AND FORM -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <section class="lg:col-span-2 bg-white rounded-2xl
                        border border-slate-200 shadow-sm overflow-hidden">

            <div class="p-6 border-b border-slate-200">
                <h3 class="text-lg font-bold">Staff Directory</h3>
                <p class="text-sm text-slate-500 mt-1">
                    Active employees and their current department assignments.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">

                    <thead class="bg-slate-50 text-slate-500 text-xs uppercase">
                        <tr>
                            <th class="px-6 py-4">Name</th>
                            <th class="px-6 py-4">Email</th>
                            <th class="px-6 py-4">Role</th>
                            <th class="px-6 py-4">Departments</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">

                        <?php foreach ($staffMembers as $member): ?>
                            <?php
                            $memberAssignments =
                                $assignmentsByStaff[(int) $member['id']] ?? [];
                            ?>

                            <tr class="hover:bg-slate-50">

                                <td class="px-6 py-4 font-semibold">
                                    <?= Security::escape((string) $member['name']) ?>
                                </td>

                                <td class="px-6 py-4 text-slate-500">
                                    <?= Security::escape((string) $member['email']) ?>
                                </td>

                                <td class="px-6 py-4 text-slate-500">
                                    <?= Security::escape((string) $member['role']) ?>
                                </td>

                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-2">

                                        <?php if (!$memberAssignments): ?>
                                            <span class="text-slate-400 text-xs">
                                                Not assigned
                                            </span>
                                        <?php endif; ?>

                                        <?php foreach ($memberAssignments as $assignment): ?>

                                            <span class="<?= $assignment['primary']
                                                ? 'bg-blue-50 text-blue-700 border-blue-200'
                                                : 'bg-slate-100 text-slate-600 border-slate-200'
                                            ?> inline-flex items-center rounded-lg border
                                               px-3 py-1 text-xs font-medium">

                                                <?= Security::escape($assignment['name']) ?>

                                                <?php if ($assignment['primary']): ?>
                                                    <span class="ml-1 font-bold">
                                                        (Primary)
                                                    </span>
                                                <?php endif; ?>

                                            </span>

                                        <?php endforeach; ?>

                                    </div>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>
                </table>
            </div>

            <?php if (!$staffMembers): ?>
                <div class="p-8 text-center text-slate-500">
                    No active staff members found.
                </div>
            <?php endif; ?>

        </section>

        <!-- ASSIGNMENT FORM -->
        <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">

            <h3 class="text-lg font-bold">Assign Department</h3>

            <p class="text-sm text-slate-500 mt-2 mb-6">
                Select an employee and assign a department.
            </p>

            <form method="POST" action="assign.php" class="space-y-5">

                <?= Security::csrfField() ?>

                <div>
                    <label for="staff_id" class="block text-sm font-medium mb-2">
                        Staff Member
                    </label>

                    <select id="staff_id" name="staff_id" required
                            class="w-full border border-slate-300 rounded-lg
                                   px-3 py-3 focus:outline-none focus:ring-2
                                   focus:ring-blue-500">

                        <option value="">Select staff member</option>

                        <?php foreach ($staffMembers as $member): ?>
                            <option value="<?= (int) $member['id'] ?>">
                                <?= Security::escape((string) $member['name']) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div>
                    <label for="department_id" class="block text-sm font-medium mb-2">
                        Department
                    </label>

                    <select id="department_id" name="department_id" required
                            class="w-full border border-slate-300 rounded-lg
                                   px-3 py-3 focus:outline-none focus:ring-2
                                   focus:ring-blue-500">

                        <option value="">Select department</option>

                        <?php foreach ($departments as $department): ?>
                            <option value="<?= (int) $department['id'] ?>">
                                <?= Security::escape((string) $department['name']) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div>
                    <label for="assignment_type"
                           class="block text-sm font-medium mb-2">
                        Assignment Type
                    </label>

                    <select id="assignment_type" name="assignment_type"
                            required
                            class="w-full border border-slate-300 rounded-lg
                                   px-3 py-3 focus:outline-none focus:ring-2
                                   focus:ring-blue-500">

                        <option value="primary">Primary Department</option>
                        <option value="secondary">Secondary Department</option>

                    </select>
                </div>

                <button type="submit"
                        <?= (!$staffMembers || !$departments) ? 'disabled' : '' ?>
                        class="w-full bg-blue-600 hover:bg-blue-700
                               text-white font-semibold py-3 rounded-lg
                               transition disabled:opacity-50
                               disabled:cursor-not-allowed">
                    Assign Department
                </button>

            </form>

            <p class="text-xs text-slate-400 mt-5 leading-relaxed">
                Each employee can have one primary department and
                additional secondary department assignments.
            </p>

        </section>

    </div>

    <!-- FOOTER -->
    <footer class="mt-10 pt-6 border-t border-slate-200">
        <p class="text-xs text-slate-400">
            Fleetify 2.0 &copy; <?= date('Y') ?>
            &middot; Staff Department Assignments
            &middot; Company <?= $companyId ?>
        </p>
    </footer>

</main>

</body>
</html>
