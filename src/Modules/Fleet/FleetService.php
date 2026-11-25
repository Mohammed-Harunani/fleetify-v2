<?php
declare(strict_types=1);

namespace Fleetify\Modules\Fleet;

use Fleetify\Core\Database;
use Fleetify\Security\AccessControl;
use Fleetify\Security\BranchScope;
use PDO;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

final class FleetService
{
    public function __construct(private ?PDO $db = null)
    {
    }

    public function listVehicles(array $actor): array
    {
        $db = $this->db ?? Database::connection();

        (new AccessControl($db))->requirePermission('fleet.view', $actor);

        $scope = (new BranchScope($db))->apply('v', $actor);

        $sql = 'SELECT
                    v.id,
                    v.company_id,
                    v.office_id,
                    v.registration_number,
                    v.vehicle_type,
                    v.capacity_kg,
                    v.status,
                    v.created_at
                FROM vehicles v
                WHERE ' . $scope['sql'] . '
                ORDER BY v.registration_number';

        $query = $db->prepare($sql);
        $query->execute($scope['params']);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getVehicle(array $actor, int $vehicleId): array
    {
        $db = $this->db ?? Database::connection();

        (new AccessControl($db))->requirePermission('fleet.view', $actor);

        $scope = (new BranchScope($db))->apply('v', $actor);

        $sql = 'SELECT
                    v.id,
                    v.company_id,
                    v.office_id,
                    v.registration_number,
                    v.vehicle_type,
                    v.capacity_kg,
                    v.status,
                    v.created_at
                FROM vehicles v
                WHERE v.id = :vehicle_id
                  AND ' . $scope['sql'] . '
                LIMIT 1';

        $query = $db->prepare($sql);

        $query->execute(
            array_merge(
                ['vehicle_id' => $vehicleId],
                $scope['params']
            )
        );

        $vehicle = $query->fetch(PDO::FETCH_ASSOC);

        if (!$vehicle) {
            throw new RuntimeException('Vehicle not found.', 404);
        }

        return $vehicle;
    }

    public function registerVehicle(array $actor, array $data): int
    {
        $db = $this->db ?? Database::connection();

        (new AccessControl($db))->requirePermission('vehicles.manage', $actor);

        $companyId = (int) $actor['company_id'];
        $actorId = (int) $actor['id'];

        $registration = strtoupper(trim((string) ($data['registration_number'] ?? '')));
        $vehicleType = (string) ($data['vehicle_type'] ?? '');
        $capacity = $data['capacity_kg'] ?? null;
        $officeId = filter_var(
            $data['office_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($registration === '' || strlen($registration) > 40) {
            throw new InvalidArgumentException('Invalid vehicle registration.');
        }

        if (!in_array($vehicleType, ['bike', 'van', 'pickup', 'truck', 'trailer'], true)) {
            throw new InvalidArgumentException('Invalid vehicle type.');
        }

        if (!is_numeric($capacity) || !is_finite((float) $capacity)
            || (float) $capacity <= 0 || (float) $capacity > 9999999999.99) {
            throw new InvalidArgumentException('Vehicle capacity must be positive.');
        }

        if ($officeId === false || $officeId === null || $officeId <= 0) {
            throw new InvalidArgumentException('A valid office is required.');
        }

        $db->beginTransaction();

        try {
            $officeQuery = $db->prepare(
                "SELECT id
                 FROM offices
                 WHERE company_id = :company
                   AND id = :office
                   AND status = 'active'
                 FOR UPDATE"
            );

            $officeQuery->execute([
                'company' => $companyId,
                'office' => $officeId
            ]);

            if ($officeQuery->fetchColumn() === false) {
                throw new RuntimeException('Office unavailable.', 404);
            }

            if (!(new AccessControl($db))->can('branches.global', $actor)) {
                $branchQuery = $db->prepare(
                    'SELECT 1
                     FROM staff_branch_access
                     WHERE company_id = :company
                       AND user_id = :user
                       AND office_id = :office
                     LIMIT 1'
                );

                $branchQuery->execute([
                    'company' => $companyId,
                    'user' => $actorId,
                    'office' => $officeId
                ]);

                if ($branchQuery->fetchColumn() === false) {
                    throw new RuntimeException('Branch access denied.', 403);
                }
            }

            $insert = $db->prepare(
                "INSERT INTO vehicles
                    (company_id, office_id, registration_number,
                     vehicle_type, capacity_kg, status)
                 VALUES
                    (:company, :office, :registration,
                     :vehicle_type, :capacity, 'available')"
            );

            $insert->execute([
                'company' => $companyId,
                'office' => $officeId,
                'registration' => $registration,
                'vehicle_type' => $vehicleType,
                'capacity' => $capacity
            ]);

            $vehicleId = (int) $db->lastInsertId();

            $this->writeAudit(
                $db,
                $companyId,
                $actorId,
                'vehicle.registered',
                'vehicles',
                $vehicleId,
                null,
                [
                    'office_id' => $officeId,
                    'registration_number' => $registration,
                    'vehicle_type' => $vehicleType,
                    'capacity_kg' => (float) $capacity,
                    'status' => 'available'
                ]
            );

            $db->commit();

            return $vehicleId;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    public function listDriverAssignments(array $actor, int $vehicleId): array
    {
        $db = $this->db ?? Database::connection();

        (new AccessControl($db))->requirePermission('fleet.view', $actor);

        $this->getVehicle($actor, $vehicleId);

        $query = $db->prepare(
            'SELECT
                a.id,
                a.company_id,
                a.vehicle_id,
                a.driver_user_id,
                u.name AS driver_name,
                a.assigned_by,
                a.assigned_at,
                a.ended_at,
                a.notes
             FROM vehicle_driver_assignments a
             JOIN users u
               ON u.company_id = a.company_id
              AND u.id = a.driver_user_id
             WHERE a.company_id = :company
               AND a.vehicle_id = :vehicle
             ORDER BY a.assigned_at DESC, a.id DESC'
        );

        $query->execute([
            'company' => (int) $actor['company_id'],
            'vehicle' => $vehicleId
        ]);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function assignDriver(
        array $actor,
        int $vehicleId,
        int $driverUserId,
        ?string $notes = null
    ): int {
        $db = $this->db ?? Database::connection();

        (new AccessControl($db))->requirePermission('vehicles.manage', $actor);

        if ($vehicleId <= 0 || $driverUserId <= 0) {
            throw new InvalidArgumentException('Invalid vehicle or driver ID.');
        }

        $notes = $notes !== null ? trim($notes) : null;

        if ($notes === '') {
            $notes = null;
        }

        if ($notes !== null && strlen($notes) > 500) {
            throw new InvalidArgumentException('Assignment notes are too long.');
        }

        $companyId = (int) $actor['company_id'];
        $actorId = (int) $actor['id'];

        $db->beginTransaction();

        try {
            // Lock the driver first to serialize competing assignments
            // involving the same driver across different vehicles.
            $driverQuery = $db->prepare(
                "SELECT id, office_id
                 FROM users
                 WHERE company_id = :company
                   AND id = :driver
                   AND role = 'driver'
                   AND status = 'active'
                 FOR UPDATE"
            );

            $driverQuery->execute([
                'company' => $companyId,
                'driver' => $driverUserId
            ]);

            $driver = $driverQuery->fetch(PDO::FETCH_ASSOC);

            if (!$driver) {
                throw new RuntimeException('Active driver not found.', 404);
            }

            // Lock the vehicle to serialize competing assignments
            // to the same vehicle.
            $vehicleQuery = $db->prepare(
                'SELECT id, office_id, status
                 FROM vehicles
                 WHERE company_id = :company
                   AND id = :vehicle
                 FOR UPDATE'
            );

            $vehicleQuery->execute([
                'company' => $companyId,
                'vehicle' => $vehicleId
            ]);

            $vehicle = $vehicleQuery->fetch(PDO::FETCH_ASSOC);

            if (!$vehicle) {
                throw new RuntimeException('Vehicle not found.', 404);
            }

            $this->assertVehicleBranchAccess(
                $db,
                $actor,
                $vehicle
            );

            if ($vehicle['status'] === 'maintenance') {
                throw new RuntimeException(
                    'Vehicle is unavailable for driver assignment.',
                    409
                );
            }

            $existingQuery = $db->prepare(
                'SELECT id
                 FROM vehicle_driver_assignments
                 WHERE company_id = :company
                   AND ended_at IS NULL
                   AND (
                       vehicle_id = :vehicle
                       OR driver_user_id = :driver
                   )
                 LIMIT 1'
            );

            $existingQuery->execute([
                'company' => $companyId,
                'vehicle' => $vehicleId,
                'driver' => $driverUserId
            ]);

            if ($existingQuery->fetchColumn() !== false) {
                throw new RuntimeException(
                    'Vehicle or driver already has an active assignment.',
                    409
                );
            }

            $insert = $db->prepare(
                'INSERT INTO vehicle_driver_assignments
                    (company_id, vehicle_id, driver_user_id,
                     assigned_by, notes)
                 VALUES
                    (:company, :vehicle, :driver, :actor, :notes)'
            );

            $insert->execute([
                'company' => $companyId,
                'vehicle' => $vehicleId,
                'driver' => $driverUserId,
                'actor' => $actorId,
                'notes' => $notes
            ]);

            $assignmentId = (int) $db->lastInsertId();

            $this->writeAudit(
                $db,
                $companyId,
                $actorId,
                'vehicle.driver_assigned',
                'vehicle_driver_assignments',
                $assignmentId,
                null,
                [
                    'vehicle_id' => $vehicleId,
                    'driver_user_id' => $driverUserId,
                    'notes' => $notes
                ]
            );

            $db->commit();

            return $assignmentId;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    public function releaseDriver(array $actor, int $assignmentId): void
    {
        $db = $this->db ?? Database::connection();

        (new AccessControl($db))->requirePermission('vehicles.manage', $actor);

        if ($assignmentId <= 0) {
            throw new InvalidArgumentException('Invalid assignment ID.');
        }

        $companyId = (int) $actor['company_id'];
        $actorId = (int) $actor['id'];

        $db->beginTransaction();

        try {
            // Identify the driver before locking the assignment.
            $lookup = $db->prepare(
                'SELECT driver_user_id
                 FROM vehicle_driver_assignments
                 WHERE company_id = :company
                   AND id = :assignment'
            );

            $lookup->execute([
                'company' => $companyId,
                'assignment' => $assignmentId
            ]);

            $driverUserId = $lookup->fetchColumn();

            if ($driverUserId === false) {
                throw new RuntimeException('Assignment not found.', 404);
            }

            // Use the same driver-first lock order as assignDriver().
            $driverLock = $db->prepare(
                'SELECT id
                 FROM users
                 WHERE company_id = :company
                   AND id = :driver
                 FOR UPDATE'
            );

            $driverLock->execute([
                'company' => $companyId,
                'driver' => (int) $driverUserId
            ]);

            $assignmentQuery = $db->prepare(
                'SELECT
                    id,
                    vehicle_id,
                    driver_user_id,
                    assigned_at,
                    ended_at
                 FROM vehicle_driver_assignments
                 WHERE company_id = :company
                   AND id = :assignment
                 FOR UPDATE'
            );

            $assignmentQuery->execute([
                'company' => $companyId,
                'assignment' => $assignmentId
            ]);

            $assignment = $assignmentQuery->fetch(PDO::FETCH_ASSOC);

            if (!$assignment) {
                throw new RuntimeException('Assignment not found.', 404);
            }

            if ($assignment['ended_at'] !== null) {
                throw new RuntimeException(
                    'Driver assignment has already ended.',
                    409
                );
            }

            $vehicleQuery = $db->prepare(
                'SELECT id, office_id
                 FROM vehicles
                 WHERE company_id = :company
                   AND id = :vehicle
                 FOR UPDATE'
            );

            $vehicleQuery->execute([
                'company' => $companyId,
                'vehicle' => (int) $assignment['vehicle_id']
            ]);

            $vehicle = $vehicleQuery->fetch(PDO::FETCH_ASSOC);

            if (!$vehicle) {
                throw new RuntimeException('Vehicle not found.', 404);
            }

            $this->assertVehicleBranchAccess(
                $db,
                $actor,
                $vehicle
            );

            $update = $db->prepare(
                'UPDATE vehicle_driver_assignments
                 SET ended_at = GREATEST(
                     CURRENT_TIMESTAMP,
                     assigned_at
                 )
                 WHERE company_id = :company
                   AND id = :assignment
                   AND ended_at IS NULL'
            );

            $update->execute([
                'company' => $companyId,
                'assignment' => $assignmentId
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to release driver assignment.',
                    409
                );
            }

            $this->writeAudit(
                $db,
                $companyId,
                $actorId,
                'vehicle.driver_released',
                'vehicle_driver_assignments',
                $assignmentId,
                [
                    'vehicle_id' => (int) $assignment['vehicle_id'],
                    'driver_user_id' => (int) $assignment['driver_user_id'],
                    'ended_at' => null
                ],
                [
                    'vehicle_id' => (int) $assignment['vehicle_id'],
                    'driver_user_id' => (int) $assignment['driver_user_id'],
                    'ended_at' => $db->query(
                        'SELECT CURRENT_TIMESTAMP'
                    )->fetchColumn()
                ]
            );

            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    private function assertVehicleBranchAccess(
        PDO $db,
        array $actor,
        array $vehicle
    ): void {
        $access = new AccessControl($db);

        if ($access->can('branches.global', $actor)) {
            return;
        }

        $officeId = $vehicle['office_id'] ?? null;

        if ($officeId === null) {
            throw new RuntimeException('Branch access denied.', 403);
        }

        $query = $db->prepare(
            "SELECT 1
             FROM staff_branch_access sba
             JOIN offices o
               ON o.company_id = sba.company_id
              AND o.id = sba.office_id
             WHERE sba.company_id = :company
               AND sba.user_id = :user
               AND sba.office_id = :office
               AND o.status = 'active'
             LIMIT 1"
        );

        $query->execute([
            'company' => (int) $actor['company_id'],
            'user' => (int) $actor['id'],
            'office' => (int) $officeId
        ]);

        if ($query->fetchColumn() === false) {
            throw new RuntimeException('Branch access denied.', 403);
        }
    }

    private function writeAudit(
        PDO $db,
        int $companyId,
        int $actorId,
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValues,
        ?array $newValues
    ): void {
        $audit = $db->prepare(
            'INSERT INTO audit_logs
                (company_id, actor_user_id, action,
                 entity_type, entity_id, old_values, new_values)
             VALUES
                (:company, :actor, :action,
                 :entity_type, :entity_id, :old_values, :new_values)'
        );

        $audit->execute([
            'company' => $companyId,
            'actor' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues === null
                ? null
                : json_encode($oldValues, JSON_THROW_ON_ERROR),
            'new_values' => $newValues === null
                ? null
                : json_encode($newValues, JSON_THROW_ON_ERROR)
        ]);
    }

    public function listOdometerLogs(array $actor, int $vehicleId): array
    {
        $db = $this->db ?? Database::connection();
        (new AccessControl($db))->requirePermission('fleet.view', $actor);
        $this->getVehicle($actor, $vehicleId);
        $q = $db->prepare('SELECT id, company_id, vehicle_id, recorded_by, odometer_km, recorded_at, notes FROM vehicle_odometer_logs WHERE company_id = :company AND vehicle_id = :vehicle ORDER BY odometer_km DESC, id DESC');
        $q->execute(['company' => (int)$actor['company_id'], 'vehicle' => $vehicleId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function recordOdometer(array $actor, int $vehicleId, mixed $kilometres, ?string $notes = null): int
    {
        $db = $this->db ?? Database::connection();
        (new AccessControl($db))->requirePermission('vehicles.manage', $actor);
        $km = $this->decimalValue($kilometres, 'Odometer reading', true);
        $notes = $this->optionalText($notes, 500, 'Odometer notes');
        $company = (int)$actor['company_id'];
        $db->beginTransaction();
        try {
            $this->lockVehicle($db, $actor, $vehicleId);
            $q = $db->prepare('SELECT MAX(odometer_km) FROM vehicle_odometer_logs WHERE company_id = :company AND vehicle_id = :vehicle');
            $q->execute(['company' => $company, 'vehicle' => $vehicleId]);
            $previous = $q->fetchColumn();
            if ($previous !== null && (float)$km < (float)$previous) {
                throw new RuntimeException('Odometer reading cannot decrease.', 409);
            }
            $q = $db->prepare('INSERT INTO vehicle_odometer_logs (company_id, vehicle_id, recorded_by, odometer_km, notes) VALUES (:company, :vehicle, :actor, :km, :notes)');
            $q->execute(['company' => $company, 'vehicle' => $vehicleId, 'actor' => (int)$actor['id'], 'km' => $km, 'notes' => $notes]);
            $id = (int)$db->lastInsertId();
            $this->writeAudit($db, $company, (int)$actor['id'], 'vehicle.odometer_recorded', 'vehicle_odometer_logs', $id, null, ['vehicle_id' => $vehicleId, 'odometer_km' => $km, 'notes' => $notes]);
            $db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function listMaintenanceSchedules(array $actor, int $vehicleId): array
    {
        $db = $this->db ?? Database::connection();
        (new AccessControl($db))->requirePermission('fleet.view', $actor);
        $this->getVehicle($actor, $vehicleId);
        $q = $db->prepare('SELECT * FROM vehicle_maintenance_schedules WHERE company_id = :company AND vehicle_id = :vehicle ORDER BY id DESC');
        $q->execute(['company' => (int)$actor['company_id'], 'vehicle' => $vehicleId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createMaintenanceSchedule(array $actor, int $vehicleId, array $data): int
    {
        $db = $this->db ?? Database::connection();
        (new AccessControl($db))->requirePermission('vehicles.manage', $actor);
        $name = trim((string)($data['service_name'] ?? ''));
        if ($name === '' || strlen($name) > 150) throw new InvalidArgumentException('Invalid service name.');
        $intervalKm = $this->optionalPositiveInt($data['interval_km'] ?? null, 'Distance interval');
        $intervalDays = $this->optionalPositiveInt($data['interval_days'] ?? null, 'Day interval');
        if ($intervalKm === null && $intervalDays === null) throw new InvalidArgumentException('At least one maintenance interval is required.');
        $nextKm = $data['next_due_km'] ?? null;
        $nextKm = $nextKm === null || $nextKm === '' ? null : $this->decimalValue($nextKm, 'Next due kilometres', true);
        $nextDate = $this->optionalDate($data['next_due_date'] ?? null);
        $company = (int)$actor['company_id'];
        $db->beginTransaction();
        try {
            $this->lockVehicle($db, $actor, $vehicleId);
            $q = $db->prepare("INSERT INTO vehicle_maintenance_schedules (company_id, vehicle_id, service_name, interval_km, interval_days, next_due_km, next_due_date, status, created_by) VALUES (:company, :vehicle, :name, :km, :days, :nextkm, :nextdate, 'active', :actor)");
            $q->execute(['company' => $company, 'vehicle' => $vehicleId, 'name' => $name, 'km' => $intervalKm, 'days' => $intervalDays, 'nextkm' => $nextKm, 'nextdate' => $nextDate, 'actor' => (int)$actor['id']]);
            $id = (int)$db->lastInsertId();
            $this->writeAudit($db, $company, (int)$actor['id'], 'vehicle.maintenance_schedule_created', 'vehicle_maintenance_schedules', $id, null, ['vehicle_id' => $vehicleId, 'service_name' => $name, 'interval_km' => $intervalKm, 'interval_days' => $intervalDays, 'next_due_km' => $nextKm, 'next_due_date' => $nextDate, 'status' => 'active']);
            $db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function changeMaintenanceScheduleStatus(array $actor, int $scheduleId, string $status): void
    {
        if (!in_array($status, ['active', 'paused', 'cancelled'], true)) throw new InvalidArgumentException('Invalid schedule status.');
        $db = $this->db ?? Database::connection();
        (new AccessControl($db))->requirePermission('vehicles.manage', $actor);
        $company = (int)$actor['company_id'];
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT vehicle_id FROM vehicle_maintenance_schedules WHERE company_id = :company AND id = :id');
            $q->execute(['company' => $company, 'id' => $scheduleId]);
            $vehicleId = $q->fetchColumn();
            if ($vehicleId === false) throw new RuntimeException('Maintenance schedule not found.', 404);
            $this->lockVehicle($db, $actor, (int)$vehicleId);
            $q = $db->prepare('SELECT status FROM vehicle_maintenance_schedules WHERE company_id = :company AND id = :id FOR UPDATE');
            $q->execute(['company' => $company, 'id' => $scheduleId]);
            $old = $q->fetchColumn();
            if ($old === false) throw new RuntimeException('Maintenance schedule not found.', 404);
            if ($old === 'cancelled' && $status !== 'cancelled') throw new RuntimeException('Cancelled schedule cannot be reactivated.', 409);
            if ($old === $status) throw new RuntimeException('Schedule already has this status.', 409);
            $q = $db->prepare('UPDATE vehicle_maintenance_schedules SET status = :status WHERE company_id = :company AND id = :id');
            $q->execute(['status' => $status, 'company' => $company, 'id' => $scheduleId]);
            $this->writeAudit($db, $company, (int)$actor['id'], 'vehicle.maintenance_schedule_status_changed', 'vehicle_maintenance_schedules', $scheduleId, ['status' => $old], ['status' => $status]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function listMaintenanceJobs(array $actor, int $vehicleId): array
    {
        $db = $this->db ?? Database::connection();
        (new AccessControl($db))->requirePermission('fleet.view', $actor);
        $this->getVehicle($actor, $vehicleId);
        $q = $db->prepare('SELECT * FROM vehicle_maintenance_jobs WHERE company_id = :company AND vehicle_id = :vehicle ORDER BY id DESC');
        $q->execute(['company' => (int)$actor['company_id'], 'vehicle' => $vehicleId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function openMaintenanceJob(array $actor, int $vehicleId, array $data): int
    {
        $db = $this->db ?? Database::connection();
        (new AccessControl($db))->requirePermission('vehicles.manage', $actor);
        $type = (string)($data['job_type'] ?? '');
        $priority = (string)($data['priority'] ?? 'normal');
        $description = trim((string)($data['description'] ?? ''));
        if (!in_array($type, ['service', 'inspection', 'repair'], true)) throw new InvalidArgumentException('Invalid job type.');
        if (!in_array($priority, ['low', 'normal', 'high', 'critical'], true)) throw new InvalidArgumentException('Invalid job priority.');
        if ($description === '' || strlen($description) > 5000) throw new InvalidArgumentException('Invalid job description.');
        $scheduleId = $data['schedule_id'] ?? null;
        if ($scheduleId === '') $scheduleId = null;
        if ($scheduleId !== null && (filter_var($scheduleId, FILTER_VALIDATE_INT) === false || (int)$scheduleId <= 0)) throw new InvalidArgumentException('Invalid schedule ID.');
        $company = (int)$actor['company_id'];
        $db->beginTransaction();
        try {
            $this->lockVehicle($db, $actor, $vehicleId);
            if ($scheduleId !== null) {
                $q = $db->prepare("SELECT id FROM vehicle_maintenance_schedules WHERE company_id = :company AND vehicle_id = :vehicle AND id = :schedule AND status = 'active'");
                $q->execute(['company' => $company, 'vehicle' => $vehicleId, 'schedule' => (int)$scheduleId]);
                if ($q->fetchColumn() === false) throw new RuntimeException('Active schedule not found for vehicle.', 404);
            }
            $q = $db->prepare("INSERT INTO vehicle_maintenance_jobs (company_id, vehicle_id, schedule_id, job_type, description, status, priority, opened_by) VALUES (:company, :vehicle, :schedule, :type, :description, 'open', :priority, :actor)");
            $q->execute(['company' => $company, 'vehicle' => $vehicleId, 'schedule' => $scheduleId === null ? null : (int)$scheduleId, 'type' => $type, 'description' => $description, 'priority' => $priority, 'actor' => (int)$actor['id']]);
            $id = (int)$db->lastInsertId();
            $this->writeAudit($db, $company, (int)$actor['id'], 'vehicle.maintenance_job_opened', 'vehicle_maintenance_jobs', $id, null, ['vehicle_id' => $vehicleId, 'schedule_id' => $scheduleId, 'job_type' => $type, 'description' => $description, 'priority' => $priority, 'status' => 'open']);
            $db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function changeMaintenanceJobStatus(array $actor, int $jobId, string $status, ?string $completionNotes = null): void
    {
        if (!in_array($status, ['in_progress', 'completed', 'cancelled'], true)) throw new InvalidArgumentException('Invalid job status.');
        $completionNotes = $this->optionalText($completionNotes, 5000, 'Completion notes');
        $db = $this->db ?? Database::connection();
        (new AccessControl($db))->requirePermission('vehicles.manage', $actor);
        $company = (int)$actor['company_id'];
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT vehicle_id FROM vehicle_maintenance_jobs WHERE company_id = :company AND id = :id');
            $q->execute(['company' => $company, 'id' => $jobId]);
            $vehicleId = $q->fetchColumn();
            if ($vehicleId === false) throw new RuntimeException('Workshop job not found.', 404);
            $this->lockVehicle($db, $actor, (int)$vehicleId);
            $q = $db->prepare('SELECT status, completed_at, completion_notes FROM vehicle_maintenance_jobs WHERE company_id = :company AND id = :id FOR UPDATE');
            $q->execute(['company' => $company, 'id' => $jobId]);
            $old = $q->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new RuntimeException('Workshop job not found.', 404);
            if (in_array($old['status'], ['completed', 'cancelled'], true)) throw new RuntimeException('Workshop job is already closed.', 409);
            if ($old['status'] === $status) throw new RuntimeException('Workshop job already has this status.', 409);
            if ($old['status'] === 'in_progress' && $status === 'in_progress') throw new RuntimeException('Workshop job already in progress.', 409);
            $q = $db->prepare("UPDATE vehicle_maintenance_jobs SET status = :status, completed_at = CASE WHEN :is_completed = 1 THEN GREATEST(CURRENT_TIMESTAMP, opened_at) ELSE NULL END, completion_notes = :notes WHERE company_id = :company AND id = :id");
            $q->execute(['status' => $status, 'is_completed' => $status === 'completed' ? 1 : 0, 'notes' => $status === 'completed' ? $completionNotes : null, 'company' => $company, 'id' => $jobId]);
            $this->writeAudit($db, $company, (int)$actor['id'], 'vehicle.maintenance_job_status_changed', 'vehicle_maintenance_jobs', $jobId, ['status' => $old['status'], 'completed_at' => $old['completed_at'], 'completion_notes' => $old['completion_notes']], ['status' => $status, 'completion_notes' => $status === 'completed' ? $completionNotes : null]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private function lockVehicle(PDO $db, array $actor, int $vehicleId): array
    {
        if ($vehicleId <= 0) throw new InvalidArgumentException('Invalid vehicle ID.');
        $q = $db->prepare('SELECT id, office_id, status FROM vehicles WHERE company_id = :company AND id = :vehicle FOR UPDATE');
        $q->execute(['company' => (int)$actor['company_id'], 'vehicle' => $vehicleId]);
        $vehicle = $q->fetch(PDO::FETCH_ASSOC);
        if (!$vehicle) throw new RuntimeException('Vehicle not found.', 404);
        $this->assertVehicleBranchAccess($db, $actor, $vehicle);
        return $vehicle;
    }

    private function decimalValue(mixed $value, string $label, bool $allowZero): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) throw new InvalidArgumentException("Invalid {$label}.");
        $raw = trim((string)$value);
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $raw)) throw new InvalidArgumentException("Invalid {$label}; maximum two decimal places.");
        $number = (float)$raw;
        if (!is_finite($number) || $number > 9999999999.99 || (!$allowZero && $number <= 0)) throw new InvalidArgumentException("Invalid {$label}.");
        return number_format($number, 2, '.', '');
    }

    private function optionalPositiveInt(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') return null;
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number <= 0 || $number > 2147483647) throw new InvalidArgumentException("Invalid {$label}.");
        return $number;
    }

    private function optionalText(?string $value, int $maxLength, string $label): ?string
    {
        if ($value === null) return null;
        $value = trim($value);
        if ($value === '') return null;
        if (strlen($value) > $maxLength) throw new InvalidArgumentException("{$label} is too long.");
        return $value;
    }

    private function optionalDate(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) throw new InvalidArgumentException('Invalid due date.');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Invalid due date.');
        return $value;
    }
}