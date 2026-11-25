<?php
declare(strict_types=1);

namespace Fleetify\Modules\Fleet;

use Fleetify\Core\Database;
use Fleetify\Security\AccessControl;
use Fleetify\Security\BranchScope;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** Safety state is event-sourced; never rely on a UI-only dispatch restriction. */
final class FleetSafetyService
{
    public function __construct(private ?PDO $db = null) {}

    public function listDefects(array $actor, int $vehicleId): array
    {
        $db = $this->connection();
        (new AccessControl($db))->requirePermission('fleet.view', $actor);
        $this->visibleVehicle($db, $actor, $vehicleId);
        $q = $db->prepare('SELECT * FROM vehicle_defects WHERE company_id=:company AND vehicle_id=:vehicle ORDER BY id DESC');
        $q->execute(['company'=>(int)$actor['company_id'], 'vehicle'=>$vehicleId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listGroundingEvents(array $actor, int $vehicleId): array
    {
        $db = $this->connection();
        (new AccessControl($db))->requirePermission('fleet.view', $actor);
        $this->visibleVehicle($db, $actor, $vehicleId);
        $q = $db->prepare('SELECT * FROM vehicle_grounding_events WHERE company_id=:company AND vehicle_id=:vehicle ORDER BY id DESC');
        $q->execute(['company'=>(int)$actor['company_id'], 'vehicle'=>$vehicleId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reportDefect(array $actor, int $vehicleId, string $title, string $description, string $severity): int
    {
        $title = $this->requiredText($title, 150, 'Defect title');
        $description = $this->requiredText($description, 65535, 'Defect description');
        if (!in_array($severity, ['minor','major','critical'], true)) throw new InvalidArgumentException('Invalid defect severity.');
        return $this->mutate($actor, $vehicleId, function(PDO $db, int $company, int $user) use ($vehicleId,$title,$description,$severity): int {
            $q=$db->prepare("INSERT INTO vehicle_defects (company_id,vehicle_id,reported_by,defect_title,description,severity,status) VALUES (:company,:vehicle,:actor,:title,:description,:severity,'open')");
            $q->execute(['company'=>$company,'vehicle'=>$vehicleId,'actor'=>$user,'title'=>$title,'description'=>$description,'severity'=>$severity]);
            $id=(int)$db->lastInsertId();
            $this->audit($db,$company,$user,'vehicle.defect_reported','vehicle_defects',$id,null,['vehicle_id'=>$vehicleId,'severity'=>$severity,'status'=>'open']);
            return $id;
        });
    }

    public function changeDefectStatus(array $actor, int $defectId, string $status, ?string $resolutionNotes = null): void
    {
        if (!in_array($status,['under_repair','resolved'],true)) throw new InvalidArgumentException('Invalid defect status.');
        $resolutionNotes = $resolutionNotes === null ? null : trim($resolutionNotes);
        if ($status === 'resolved' && ($resolutionNotes === null || $resolutionNotes === '')) throw new InvalidArgumentException('Resolution notes required.');
        if ($resolutionNotes !== null && strlen($resolutionNotes)>65535) throw new InvalidArgumentException('Resolution notes too long.');
        $db=$this->connection();
        (new AccessControl($db))->requirePermission('vehicles.manage',$actor);
        $vehicleId=$this->defectVehicleId($db,$actor,$defectId);
        $this->mutate($actor,$vehicleId,function(PDO $db,int $company,int $user) use ($defectId,$status,$resolutionNotes): void {
            $q=$db->prepare('SELECT id,status,severity FROM vehicle_defects WHERE company_id=:company AND id=:id FOR UPDATE');
            $q->execute(['company'=>$company,'id'=>$defectId]);
            $old=$q->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new RuntimeException('Defect not found.',404);
            if ($old['status']==='resolved' || $old['status']===$status) throw new RuntimeException('Invalid defect transition.',409);
            $q=$db->prepare("UPDATE vehicle_defects SET status=:status, resolved_by=:resolver, resolved_at=CASE WHEN :resolved=1 THEN CURRENT_TIMESTAMP ELSE NULL END, resolution_notes=:notes WHERE company_id=:company AND id=:id");
            $q->execute(['status'=>$status,'resolver'=>$status==='resolved'?$user:null,'resolved'=>$status==='resolved'?1:0,'notes'=>$status==='resolved'?$resolutionNotes:null,'company'=>$company,'id'=>$defectId]);
            $this->audit($db,$company,$user,'vehicle.defect_status_changed','vehicle_defects',$defectId,['status'=>$old['status']],['status'=>$status,'resolution_notes'=>$status==='resolved'?$resolutionNotes:null]);
        });
    }

    public function groundVehicle(array $actor, int $vehicleId, string $reason, ?int $defectId = null): int
    {
        $reason=$this->requiredText($reason,65535,'Grounding reason');
        return $this->mutate($actor,$vehicleId,function(PDO $db,int $company,int $user) use ($vehicleId,$reason,$defectId): int {
            if ($this->latestGroundingAction($db,$company,$vehicleId)==='grounded') throw new RuntimeException('Vehicle is already grounded.',409);
            if ($defectId !== null) {
                $q=$db->prepare('SELECT id,status FROM vehicle_defects WHERE company_id=:company AND vehicle_id=:vehicle AND id=:defect');
                $q->execute(['company'=>$company,'vehicle'=>$vehicleId,'defect'=>$defectId]);
                $defect=$q->fetch(PDO::FETCH_ASSOC);
                if (!$defect || $defect['status']==='resolved') throw new RuntimeException('Unresolved defect not found for vehicle.',404);
            }
            return $this->insertEvent($db,$company,$user,$vehicleId,$defectId,'grounded',$reason);
        });
    }

    public function releaseVehicle(array $actor, int $vehicleId, string $reason): int
    {
        $reason=$this->requiredText($reason,65535,'Release reason');
        return $this->mutate($actor,$vehicleId,function(PDO $db,int $company,int $user) use ($vehicleId,$reason): int {
            if ($this->latestGroundingAction($db,$company,$vehicleId)!=='grounded') throw new RuntimeException('Vehicle is not grounded.',409);
            if ($this->criticalCount($db,$company,$vehicleId)>0) throw new RuntimeException('Unresolved critical defects prevent release.',409);
            return $this->insertEvent($db,$company,$user,$vehicleId,null,'released',$reason);
        });
    }

    /** Call inside the same transaction and after locking the vehicle row when dispatching. */
    public function assertDispatchEligible(array $actor, int $vehicleId): void
    {
        $db=$this->connection();
        (new AccessControl($db))->requirePermission('vehicles.manage',$actor);
        // Reentrant use in a dispatch transaction is supported; do not commit here.
        $this->lockedVehicle($db,$actor,$vehicleId);
        $this->assertEligibleState($db,(int)$actor['company_id'],$vehicleId);
    }

    public function getSafetyStatus(array $actor, int $vehicleId): array
    {
        $db=$this->connection();
        (new AccessControl($db))->requirePermission('fleet.view',$actor);
        $vehicle=$this->visibleVehicle($db,$actor,$vehicleId);
        $critical=$this->criticalCount($db,(int)$actor['company_id'],$vehicleId);
        $grounded=$this->latestGroundingAction($db,(int)$actor['company_id'],$vehicleId)==='grounded';
        $reasons=[];
        if ($grounded) $reasons[]='grounded';
        if ($critical>0) $reasons[]='unresolved_critical_defect';
        if ($vehicle['status']!=='available') $reasons[]='vehicle_not_available';
        return ['vehicle_id'=>$vehicleId,'eligible'=>$reasons===[],'grounded'=>$grounded,'unresolved_critical_defects'=>$critical,'reasons'=>$reasons];
    }

    private function assertEligibleState(PDO $db,int $company,int $vehicleId): void
    {
        $q=$db->prepare('SELECT status FROM vehicles WHERE company_id=:company AND id=:vehicle');
        $q->execute(['company'=>$company,'vehicle'=>$vehicleId]);
        if ($q->fetchColumn()!=='available') throw new RuntimeException('Vehicle is not available for dispatch.',409);
        if ($this->latestGroundingAction($db,$company,$vehicleId)==='grounded') throw new RuntimeException('Grounded vehicle cannot be dispatched.',409);
        if ($this->criticalCount($db,$company,$vehicleId)>0) throw new RuntimeException('Unresolved critical defect prevents dispatch.',409);
    }

    private function criticalCount(PDO $db,int $company,int $vehicleId): int
    {
        $q=$db->prepare("SELECT COUNT(*) FROM vehicle_defects WHERE company_id=:company AND vehicle_id=:vehicle AND severity='critical' AND status<>'resolved'");
        $q->execute(['company'=>$company,'vehicle'=>$vehicleId]);
        return (int)$q->fetchColumn();
    }

    private function latestGroundingAction(PDO $db,int $company,int $vehicleId): ?string
    {
        // ID is the immutable event sequence; timestamps may tie at second precision.
        $q=$db->prepare('SELECT action FROM vehicle_grounding_events WHERE company_id=:company AND vehicle_id=:vehicle ORDER BY id DESC LIMIT 1');
        $q->execute(['company'=>$company,'vehicle'=>$vehicleId]);
        $value=$q->fetchColumn();
        return $value===false?null:(string)$value;
    }

    private function insertEvent(PDO $db,int $company,int $user,int $vehicleId,?int $defectId,string $action,string $reason): int
    {
        $q=$db->prepare('INSERT INTO vehicle_grounding_events (company_id,vehicle_id,defect_id,action,reason,performed_by) VALUES (:company,:vehicle,:defect,:action,:reason,:actor)');
        $q->execute(['company'=>$company,'vehicle'=>$vehicleId,'defect'=>$defectId,'action'=>$action,'reason'=>$reason,'actor'=>$user]);
        $id=(int)$db->lastInsertId();
        $this->audit($db,$company,$user,'vehicle.'.$action,'vehicle_grounding_events',$id,null,['vehicle_id'=>$vehicleId,'defect_id'=>$defectId,'action'=>$action,'reason'=>$reason]);
        return $id;
    }

    private function defectVehicleId(PDO $db,array $actor,int $defectId): int
    {
        $q=$db->prepare('SELECT vehicle_id FROM vehicle_defects WHERE company_id=:company AND id=:id');
        $q->execute(['company'=>(int)$actor['company_id'],'id'=>$defectId]);
        $id=$q->fetchColumn();
        if ($id===false) throw new RuntimeException('Defect not found.',404);
        return (int)$id;
    }

    private function visibleVehicle(PDO $db,array $actor,int $vehicleId): array
    {
        $scope=(new BranchScope($db))->apply('v',$actor);
        $q=$db->prepare('SELECT v.id,v.office_id,v.status FROM vehicles v WHERE v.id=:vehicle AND '.$scope['sql'].' LIMIT 1');
        $q->execute(array_merge(['vehicle'=>$vehicleId],$scope['params']));
        $vehicle=$q->fetch(PDO::FETCH_ASSOC);
        if (!$vehicle) throw new RuntimeException('Vehicle not found or inaccessible.',404);
        return $vehicle;
    }

    private function lockedVehicle(PDO $db,array $actor,int $vehicleId): array
    {
        $q=$db->prepare('SELECT id,office_id,status FROM vehicles WHERE company_id=:company AND id=:vehicle FOR UPDATE');
        $q->execute(['company'=>(int)$actor['company_id'],'vehicle'=>$vehicleId]);
        $vehicle=$q->fetch(PDO::FETCH_ASSOC);
        if (!$vehicle) throw new RuntimeException('Vehicle not found.',404);
        // Check branch permission against the locked row.
        if (!(new AccessControl($db))->can('branches.global',$actor)) {
            if ($vehicle['office_id']===null) throw new RuntimeException('Branch access denied.',403);
            $q=$db->prepare("SELECT 1 FROM staff_branch_access s JOIN offices o ON o.company_id=s.company_id AND o.id=s.office_id AND o.status='active' WHERE s.company_id=:company AND s.user_id=:user AND s.office_id=:office LIMIT 1");
            $q->execute(['company'=>(int)$actor['company_id'],'user'=>(int)$actor['id'],'office'=>(int)$vehicle['office_id']]);
            if ($q->fetchColumn()===false) throw new RuntimeException('Branch access denied.',403);
        }
        return $vehicle;
    }

    private function mutate(array $actor,int $vehicleId,callable $callback): mixed
    {
        $db=$this->connection();
        (new AccessControl($db))->requirePermission('vehicles.manage',$actor);
        if ($db->inTransaction()) throw new RuntimeException('Safety mutation requires its own transaction.');
        $db->beginTransaction();
        try {
            $this->lockedVehicle($db,$actor,$vehicleId);
            $result=$callback($db,(int)$actor['company_id'],(int)$actor['id']);
            $db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private function requiredText(string $text,int $max,string $label): string
    {
        $text=trim($text);
        if ($text==='' || strlen($text)>$max) throw new InvalidArgumentException('Invalid '.$label.'.');
        return $text;
    }

    private function audit(PDO $db,int $company,int $actor,string $action,string $entity,int $id,?array $old,?array $new): void
    {
        $q=$db->prepare('INSERT INTO audit_logs (company_id,actor_user_id,action,entity_type,entity_id,old_values,new_values) VALUES (:company,:actor,:action,:entity,:id,:old,:new)');
        $q->execute(['company'=>$company,'actor'=>$actor,'action'=>$action,'entity'=>$entity,'id'=>$id,'old'=>$old===null?null:json_encode($old,JSON_THROW_ON_ERROR),'new'=>$new===null?null:json_encode($new,JSON_THROW_ON_ERROR)]);
    }

    private function connection(): PDO { return $this->db ?? Database::connection(); }
}
