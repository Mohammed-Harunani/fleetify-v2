<?php
declare(strict_types=1);
require dirname(__DIR__).'/config/bootstrap.php';
use Fleetify\Core\Database;
use Fleetify\Modules\Fleet\FleetSafetyService;

$db=Database::connection();
$service=new FleetSafetyService($db);
$actor=['id'=>1,'company_id'=>1,'role'=>'admin'];
$vehicle=2;
$results=[];
$defects=[];
$events=[];
$check=function(string $name,bool $ok) use (&$results): void { $results[$name]=$ok; echo $name.': '.($ok?'PASS':'FAIL').PHP_EOL; };
$reject=function(callable $fn): bool { try { $fn(); return false; } catch (\InvalidArgumentException|\RuntimeException $e) { return true; } };
try {
    $before=$service->getSafetyStatus($actor,$vehicle);
    if (!$before['eligible']) throw new RuntimeException('Fixture vehicle must initially be dispatch eligible; clean up earlier safety data before running tests.');
    $check('Initial dispatch eligible', $before['eligible']);
    $check('Empty defect title rejected',$reject(fn()=>$service->reportDefect($actor,$vehicle,' ','details','minor')));
    $check('Invalid severity rejected',$reject(fn()=>$service->reportDefect($actor,$vehicle,'Test','details','severe')));
    $check('Cross-company report rejected',$reject(fn()=>$service->reportDefect($actor,3,'Test','details','minor')));
    $check('Forged role rejected',$reject(fn()=>$service->reportDefect(['id'=>1,'company_id'=>1,'role'=>'driver'],$vehicle,'Test','details','minor')));
    $critical=$service->reportDefect($actor,$vehicle,'Batch 2 critical test','Temporary safety test','critical'); $defects[]=$critical;
    $check('Critical defect created',$critical>0);
    $status=$service->getSafetyStatus($actor,$vehicle);
    $check('Critical defect blocks dispatch',!$status['eligible'] && $status['unresolved_critical_defects']===1);
    $check('Dispatch assertion rejects critical defect',$reject(fn()=>$service->assertDispatchEligible($actor,$vehicle)));
    $check('Cross-company safety status rejected',$reject(fn()=>$service->getSafetyStatus($actor,3)));
    $check('Resolution requires notes',$reject(fn()=>$service->changeDefectStatus($actor,$critical,'resolved','')));
    $service->changeDefectStatus($actor,$critical,'under_repair');
    $check('Defect moved to repair',($service->listDefects($actor,$vehicle)[0]['status']??'')==='under_repair');
    $ground=$service->groundVehicle($actor,$vehicle,'Critical defect test',$critical); $events[]=$ground;
    $check('Grounding recorded',$ground>0);
    $check('Duplicate grounding rejected',$reject(fn()=>$service->groundVehicle($actor,$vehicle,'Duplicate')));
    $check('Release blocked by critical defect',$reject(fn()=>$service->releaseVehicle($actor,$vehicle,'Not ready')));
    $check('Grounding blocks dispatch',$reject(fn()=>$service->assertDispatchEligible($actor,$vehicle)));
    $service->changeDefectStatus($actor,$critical,'resolved','Repaired and inspected');
    $check('Defect resolved',($service->listDefects($actor,$vehicle)[0]['status']??'')==='resolved');
    $check('Grounding persists after repair',!$service->getSafetyStatus($actor,$vehicle)['eligible']);
    $release=$service->releaseVehicle($actor,$vehicle,'Inspected and safe'); $events[]=$release;
    $check('Release recorded',$release>0);
    $check('Repeat release rejected',$reject(fn()=>$service->releaseVehicle($actor,$vehicle,'Repeat')));
    $check('Dispatch eligible after release',$service->getSafetyStatus($actor,$vehicle)['eligible']);
    $service->assertDispatchEligible($actor,$vehicle);
    $check('Dispatch assertion accepts safe vehicle',true);
    $check('Grounding history recorded',count($service->listGroundingEvents($actor,$vehicle))>=2);
    $check('Defect history recorded',count($service->listDefects($actor,$vehicle))>=1);
    $q=$db->prepare("SELECT COUNT(*) FROM audit_logs WHERE company_id=1 AND entity_type IN ('vehicle_defects','vehicle_grounding_events') AND entity_id IN (".implode(',',array_map('intval',array_merge($defects,$events))).") AND action LIKE 'vehicle.%'");
    $q->execute();
    $check('Safety audit entries present',(int)$q->fetchColumn()>=5);
} catch (\Throwable $e) { $check('Unexpected exception: '.$e->getMessage(),false); }
finally {
    try {
        $db->beginTransaction();
        foreach ($events as $id) {
            $q=$db->prepare("DELETE FROM audit_logs WHERE company_id=1 AND entity_type='vehicle_grounding_events' AND entity_id=:id"); $q->execute(['id'=>$id]);
            $q=$db->prepare('DELETE FROM vehicle_grounding_events WHERE company_id=1 AND id=:id'); $q->execute(['id'=>$id]);
        }
        foreach ($defects as $id) {
            $q=$db->prepare("DELETE FROM audit_logs WHERE company_id=1 AND entity_type='vehicle_defects' AND entity_id=:id"); $q->execute(['id'=>$id]);
            $q=$db->prepare('DELETE FROM vehicle_defects WHERE company_id=1 AND id=:id'); $q->execute(['id'=>$id]);
        }
        $db->commit();
        $check('Temporary safety data cleaned',true);
    } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); $check('Cleanup: '.$e->getMessage(),false); }
}
$passed=count(array_filter($results));
echo 'RESULT: '.$passed.'/'.count($results).' tests passed'.PHP_EOL;
exit($passed===count($results)?0:1);
