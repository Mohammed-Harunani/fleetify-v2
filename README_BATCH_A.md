# Fleetify 2.0 — Phase 24 / Module 1 / Batch A

This package adds tenant-scoped department, branch, permission and audit infrastructure without modifying Phase 23 PHP files.

## Installation
1. Back up `fleetify_v2` first. Confirm no concurrent schema migrations.
2. Extract the archive into `C:\xampp\htdocs\fleetify-v2`, preserving paths. Review changes before copying. Do not copy `README_BATCH_A.md` into the public web root.
3. Run `C:\xampp\mysql\bin\mysql.exe -u root fleetify_v2 < C:\xampp\htdocs\fleetify-v2\database\migrations\002_department_foundation.sql` from CMD, or use the PowerShell instructions in chat (PowerShell input redirection differs).
4. Run the verification queries from chat.

## Security contract
- `AccessControl::can($slug, $staff)` checks active user, tenant and database-backed role grants.
- `BranchScope::apply($alias, $staff)` returns `['sql'=>predicate,'params'=>bindings]`. The caller MUST append the SQL to the WHERE clause and bind the params; never use it as an optional UI-only filter. It applies to tables with both `company_id` and `office_id`.
- `branches.global` is an explicit permission granted to admins during migration. Other roles receive only assigned active offices.
- `ModuleGuard::isEnabled($code, $staff)` checks the tenant department switch, not user membership. Use AccessControl and department membership checks in addition when required.
- `DepartmentService` performs mutations and audit inserts in the same database transaction.
- Existing Phase 23 StaffGuard and Authorization are unchanged in Batch A. Switch new module entry points to AccessControl in Batch B; don't assume the old hardcoded map enforces new grants.

## Limitations and follow-on work
- Schema seeds apply to companies existing at migration time. Tenant onboarding must seed departments and role grants for new companies in a subsequent batch.
- Existing users receive a default department based on role; review assignments before enabling departmental UI.
- `users.role` is still an enum; custom roles are deferred until a safe migration is designed.
- Scope for consignments requires explicit origin/destination office rules, because the table has `origin_office_id` and `destination_office_id` rather than `office_id`.
- Global reference tables (`countries`, `states`, `cities`) remain intentionally shared; not every existing table is tenant-owned.
- UI, request CSRF handling, navigation and tests are Batch B.
