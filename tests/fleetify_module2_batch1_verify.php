<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/bootstrap.php';
use Fleetify\Core\Database;
use Fleetify\Modules\Fleet\FleetService;

$db = Database::connection();
$service = new FleetService($db);
$actor = ['id'=>1,'company_id'=>1,'role'=>'admin'];
$forged = ['id'=>1,'company_id'=>1,'role'=>'driver'];
$tests = [];
$created = ['vehicle_odometer_logs'=>[], 'vehicle_maintenance_schedules'=>[], 'vehicle_maintenance_jobs'=>[]];
$entities = ['vehicle_odometer_logs','vehicle_maintenance_schedules','vehicle_maintenance_jobs'];
$check = static function(string $name, bool $ok) use (&$tests): void { $tests[$name] = $ok; };
$reject = static function(string $name, callable $fn, string $class, int $code = 0) use (&$tests): void {
    try { $fn(); $tests[$name] = false; }
    catch (Throwable $e) { $tests[$name] = $e instanceof $class && ($code === 0 || $e->getCode() === $code); }
};
$cleanupOk = true;
try {
    $vehicle = $service->getVehicle($actor, 2);
    if ((int)$vehicle['company_id'] !== 1) throw new RuntimeException('Fixture vehicle is not in company 1');
    $baseline = $db->query('SELECT MAX(odometer_km) FROM vehicle_odometer_logs WHERE company_id=1 AND vehicle_id=2')->fetchColumn();
    $reading = $baseline === null ? '12345.00' : number_format((float)$baseline + 100, 2, '.', '');
    $logId = $service->recordOdometer($actor, 2, $reading, 'Module 2 batch 1 test');
    $created['vehicle_odometer_logs'][] = $logId;
    $check('Odometer recorded', $logId > 0);
    $logs = $service->listOdometerLogs($actor, 2);
    $check('Odometer appears in history', count(array_filter($logs, static fn($r) => (int)$r['id'] === $logId && (float)$r['odometer_km'] === (float)$reading)) === 1);
    $reject('Odometer decrease rejected', static fn() => $service->recordOdometer($actor, 2, '1.00'), RuntimeException::class, 409);
    $reject('Negative odometer rejected', static fn() => $service->recordOdometer($actor, 2, '-1'), InvalidArgumentException::class);
    $reject('Odometer precision validated', static fn() => $service->recordOdometer($actor, 2, '1.234'), InvalidArgumentException::class);
    $reject('Cross-tenant odometer rejected', static fn() => $service->recordOdometer($actor, 3, '10'), RuntimeException::class, 404);
    $reject('Forged role odometer rejected', static fn() => $service->recordOdometer($forged, 2, '20000'), RuntimeException::class, 403);

    $scheduleId = $service->createMaintenanceSchedule($actor, 2, [
        'service_name'=>'Batch 1 Test Service', 'interval_km'=>10000, 'interval_days'=>180,
        'next_due_km'=>'25000.00', 'next_due_date'=>'2027-04-15'
    ]);
    $created['vehicle_maintenance_schedules'][] = $scheduleId;
    $check('Maintenance schedule created', $scheduleId > 0);
    $schedules = $service->listMaintenanceSchedules($actor, 2);
    $check('Maintenance schedule listed', count(array_filter($schedules, static fn($r) => (int)$r['id'] === $scheduleId && $r['status'] === 'active')) === 1);
    $reject('Schedule requires interval', static fn() => $service->createMaintenanceSchedule($actor, 2, ['service_name'=>'Invalid']), InvalidArgumentException::class);
    $reject('Invalid due date rejected', static fn() => $service->createMaintenanceSchedule($actor, 2, ['service_name'=>'Invalid','interval_days'=>30,'next_due_date'=>'2027-02-30']), InvalidArgumentException::class);
    $reject('Cross-tenant schedule rejected', static fn() => $service->createMaintenanceSchedule($actor, 3, ['service_name'=>'No access','interval_days'=>30]), RuntimeException::class, 404);
    $service->changeMaintenanceScheduleStatus($actor, $scheduleId, 'paused');
    $check('Schedule paused', (string)$db->query('SELECT status FROM vehicle_maintenance_schedules WHERE id='.(int)$scheduleId)->fetchColumn() === 'paused');
    $reject('Paused schedule cannot back a job', static fn() => $service->openMaintenanceJob($actor, 2, ['job_type'=>'service','description'=>'Should fail','schedule_id'=>$scheduleId]), RuntimeException::class, 404);
    $service->changeMaintenanceScheduleStatus($actor, $scheduleId, 'active');

    $jobId = $service->openMaintenanceJob($actor, 2, [
        'schedule_id'=>$scheduleId, 'job_type'=>'service', 'description'=>'Batch 1 scheduled service', 'priority'=>'high'
    ]);
    $created['vehicle_maintenance_jobs'][] = $jobId;
    $check('Workshop job created', $jobId > 0);
    $jobs = $service->listMaintenanceJobs($actor, 2);
    $check('Workshop job listed', count(array_filter($jobs, static fn($r) => (int)$r['id'] === $jobId && $r['status'] === 'open')) === 1);
    $reject('Invalid workshop type rejected', static fn() => $service->openMaintenanceJob($actor, 2, ['job_type'=>'flight','description'=>'Invalid']), InvalidArgumentException::class);
    $reject('Cross-tenant workshop job rejected', static fn() => $service->openMaintenanceJob($actor, 3, ['job_type'=>'repair','description'=>'No access']), RuntimeException::class, 404);
    $reject('Wrong vehicle schedule rejected', static fn() => $service->openMaintenanceJob($actor, 1, ['job_type'=>'service','description'=>'Wrong vehicle','schedule_id'=>$scheduleId]), RuntimeException::class, 404);
    $service->changeMaintenanceJobStatus($actor, $jobId, 'in_progress');
    $check('Workshop job started', (string)$db->query('SELECT status FROM vehicle_maintenance_jobs WHERE id='.(int)$jobId)->fetchColumn() === 'in_progress');
    $service->changeMaintenanceJobStatus($actor, $jobId, 'completed', 'Completed in regression test');
    $q = $db->query('SELECT status,completed_at,completion_notes FROM vehicle_maintenance_jobs WHERE id='.(int)$jobId);
    $done = $q->fetch(PDO::FETCH_ASSOC);
    $check('Workshop job completed with notes', $done && $done['status']==='completed' && $done['completed_at']!==null && $done['completion_notes']==='Completed in regression test');
    $reject('Closed job cannot restart', static fn() => $service->changeMaintenanceJobStatus($actor, $jobId, 'in_progress'), RuntimeException::class, 409);
    $q = $db->prepare('SELECT action,entity_type,entity_id FROM audit_logs WHERE company_id=1 AND actor_user_id=1 AND entity_type=:type AND entity_id=:id');
    foreach ([['vehicle_odometer_logs',$logId,['vehicle.odometer_recorded']], ['vehicle_maintenance_schedules',$scheduleId,['vehicle.maintenance_schedule_created','vehicle.maintenance_schedule_status_changed']], ['vehicle_maintenance_jobs',$jobId,['vehicle.maintenance_job_opened','vehicle.maintenance_job_status_changed']]] as [$type,$id,$actions]) {
        $q->execute(['type'=>$type,'id'=>$id]);
        $actual = array_column($q->fetchAll(PDO::FETCH_ASSOC),'action');
        $check('Audits present: '.$type, count(array_diff($actions,$actual)) === 0);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'TEST ERROR: '.$e->getMessage().PHP_EOL);
    $tests['Batch 1 execution'] = false;
} finally {
    try {
        if ($db->inTransaction()) $db->rollBack();
        $db->beginTransaction();
        foreach (['vehicle_maintenance_jobs','vehicle_maintenance_schedules','vehicle_odometer_logs'] as $table) {
            foreach ($created[$table] as $id) {
                $a = $db->prepare('DELETE FROM audit_logs WHERE company_id=1 AND actor_user_id=1 AND entity_type=:type AND entity_id=:id');
                $a->execute(['type'=>$table,'id'=>$id]);
                $d = $db->prepare("DELETE FROM {$table} WHERE company_id=1 AND vehicle_id=2 AND id=:id");
                $d->execute(['id'=>$id]);
                if ($d->rowCount() !== 1) throw new RuntimeException("Cleanup failed for {$table} #{$id}");
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $cleanupOk = false;
        fwrite(STDERR,'CLEANUP FAILED: '.$e->getMessage().PHP_EOL);
    }
}
$tests['Temporary records cleaned up'] = $cleanupOk;
echo "FLEETIFY V2 - MODULE 2 BATCH 1 FUNCTIONAL TESTS\n";
echo str_repeat('-', 48)."\n";
$passed = 0;
foreach ($tests as $name=>$ok) { echo $name.': '.($ok?'PASS':'FAIL').PHP_EOL; if ($ok) $passed++; }
echo str_repeat('-', 48)."\nRESULT: {$passed}/".count($tests)." tests passed\n";
exit($passed===count($tests)?0:1);
