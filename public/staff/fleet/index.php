<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/config/bootstrap.php';
use Fleetify\Auth\StaffGuard;
use Fleetify\Core\Session;
use Fleetify\Core\Database;
use Fleetify\Security\AccessControl;
use Fleetify\Modules\Fleet\FleetService;
use Fleetify\Modules\Fleet\FleetSafetyService;
Session::start();
try { $actor=(new StaffGuard())->requireLogin(); }
catch (RuntimeException $e) { header('Location: ../login.php'); exit; }
$access=new AccessControl();
$canView=$access->can('fleet.view',$actor);
$canManage=$access->can('vehicles.manage',$actor);
if (!$canView && !$canManage) { http_response_code(403); exit('Access denied.'); }
// Service methods enforce database-backed permissions and branch scope independently.
$fleet=new FleetService(); $safety=new FleetSafetyService(); $db=Database::connection();
function h(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function positiveId(mixed $v): int { $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); if ($n===false) throw new InvalidArgumentException('Invalid record ID.'); return $n; }
function postText(string $key): string { return trim((string)($_POST[$key]??'')); }
function postNullable(string $key): ?string { $v=postText($key); return $v===''?null:$v; }
if (empty($_SESSION['fleet_ui_csrf'])) $_SESSION['fleet_ui_csrf']=bin2hex(random_bytes(32));
$token=(string)$_SESSION['fleet_ui_csrf'];
$vehicleId=filter_var($_GET['vehicle']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: null;
$notice=null; $error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$canManage) { http_response_code(403); exit('Access denied.'); }
    if (!hash_equals($token,(string)($_POST['_token']??''))) { http_response_code(403); exit('Invalid CSRF token.'); }
    $action=postText('action');
    try {
        $vid=isset($_POST['vehicle_id']) && $_POST['vehicle_id']!=='' ? positiveId($_POST['vehicle_id']) : null;
        switch ($action) {
            case 'register':
                $id=$fleet->registerVehicle($actor,['registration_number'=>postText('registration_number'),'vehicle_type'=>postText('vehicle_type'),'capacity_kg'=>postText('capacity_kg'),'office_id'=>postText('office_id')]); $vid=$id; break;
            case 'assign': $fleet->assignDriver($actor,positiveId($vid),positiveId($_POST['driver_id']??null),postNullable('notes')); break;
            case 'release_driver': $fleet->releaseDriver($actor,positiveId($_POST['assignment_id']??null)); break;
            case 'odometer': $fleet->recordOdometer($actor,positiveId($vid),postText('odometer_km'),postNullable('notes')); break;
            case 'schedule': $fleet->createMaintenanceSchedule($actor,positiveId($vid),['service_name'=>postText('service_name'),'interval_km'=>postNullable('interval_km'),'interval_days'=>postNullable('interval_days'),'next_due_km'=>postNullable('next_due_km'),'next_due_date'=>postNullable('next_due_date')]); break;
            case 'schedule_status': $fleet->changeMaintenanceScheduleStatus($actor,positiveId($_POST['schedule_id']??null),postText('status')); break;
            case 'job': $fleet->openMaintenanceJob($actor,positiveId($vid),['schedule_id'=>postNullable('schedule_id'),'job_type'=>postText('job_type'),'priority'=>postText('priority'),'description'=>postText('description')]); break;
            case 'job_status': $fleet->changeMaintenanceJobStatus($actor,positiveId($_POST['job_id']??null),postText('status'),postNullable('completion_notes')); break;
            case 'defect': $safety->reportDefect($actor,positiveId($vid),postText('defect_title'),postText('description'),postText('severity')); break;
            case 'defect_status': $safety->changeDefectStatus($actor,positiveId($_POST['defect_id']??null),postText('status'),postNullable('resolution_notes')); break;
            case 'ground': $safety->groundVehicle($actor,positiveId($vid),postText('reason'),postNullable('defect_id')===null?null:positiveId($_POST['defect_id'])); break;
            case 'release': $safety->releaseVehicle($actor,positiveId($vid),postText('reason')); break;
            default: throw new InvalidArgumentException('Unknown operation.');
        }
        $_SESSION['fleet_ui_flash']='Operation completed successfully.';
        header('Location: index.php'.($vid?'?vehicle='.$vid:''),true,303); exit;
    } catch (Throwable $e) {
        $error=$e instanceof InvalidArgumentException || ($e instanceof RuntimeException && in_array($e->getCode(),[403,404,409],true)) ? $e->getMessage() : 'Operation failed. Check server logs.';
        error_log('Fleet UI action '.$action.': '.$e->getMessage());
        $vehicleId=$vid;
    }
}
$notice=$_SESSION['fleet_ui_flash']??null; unset($_SESSION['fleet_ui_flash']);
try {
    $vehicles=$fleet->listVehicles($actor);
    $selected=$vehicleId ? $fleet->getVehicle($actor,$vehicleId) : null;
    $assignments=$selected?$fleet->listDriverAssignments($actor,$vehicleId):[];
    $odometer=$selected?$fleet->listOdometerLogs($actor,$vehicleId):[];
    $schedules=$selected?$fleet->listMaintenanceSchedules($actor,$vehicleId):[];
    $jobs=$selected?$fleet->listMaintenanceJobs($actor,$vehicleId):[];
    $defects=$selected?$safety->listDefects($actor,$vehicleId):[];
    $events=$selected?$safety->listGroundingEvents($actor,$vehicleId):[];
    $safetyStatus=$selected?$safety->getSafetyStatus($actor,$vehicleId):null;
} catch (Throwable $e) { error_log('Fleet UI read: '.$e->getMessage()); http_response_code(403); exit('Unable to load fleet data.'); }
// Lookup lists are company-scoped and restricted to the branches represented by visible vehicles.
$offices=[]; $drivers=[];
if ($canManage) {
    $officeIds=array_values(array_unique(array_filter(array_map(static fn($v)=>(int)($v['office_id']??0),$vehicles))));
    $q=$db->prepare("SELECT id,name FROM offices WHERE company_id=:company AND status='active' ORDER BY name");
    $q->execute(['company'=>(int)$actor['company_id']]);
    $allOffices=$q->fetchAll(PDO::FETCH_ASSOC);
    $global=$access->can('branches.global',$actor);
    if (!$global) {
        $q=$db->prepare("SELECT office_id FROM staff_branch_access WHERE company_id=:company AND user_id=:user");
        $q->execute(['company'=>(int)$actor['company_id'],'user'=>(int)$actor['id']]);
        $officeIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    }
    $offices=array_values(array_filter($allOffices,static fn($o)=>$global || in_array((int)$o['id'],$officeIds,true)));
    $q=$db->prepare("SELECT id,name,office_id FROM users WHERE company_id=:company AND role='driver' AND status='active' ORDER BY name");
    $q->execute(['company'=>(int)$actor['company_id']]);
    $drivers=array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC),static fn($d)=>$global || ($d['office_id']!==null && in_array((int)$d['office_id'],$officeIds,true))));
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fleet Management | Fleetify</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-100 text-slate-900 min-h-screen">
<header class="bg-slate-950 text-white"><div class="max-w-7xl mx-auto px-6 py-5 flex flex-wrap justify-between gap-3"><div><h1 class="text-2xl font-bold">Fleetify</h1><p class="text-slate-400 text-sm">Fleet & Workshop Management</p></div><a class="text-sm text-blue-200 hover:text-white" href="../dashboard.php">Back to Dashboard</a></div></header>
<main class="max-w-7xl mx-auto px-6 py-8 space-y-6">
<div><h2 class="text-3xl font-bold">Fleet Management</h2><p class="text-slate-500 mt-1">Vehicle registry, maintenance, workshop and safety controls.</p></div>
<?php if ($notice): ?><div role="status" class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-3 rounded-lg"><?=h($notice)?></div><?php endif; ?>
<?php if ($error): ?><div role="alert" class="bg-red-50 border border-red-200 text-red-800 p-3 rounded-lg"><?=h($error)?></div><?php endif; ?>
<section class="bg-white rounded-xl shadow-sm p-5"><div class="flex flex-wrap justify-between items-center gap-3 mb-4"><h3 class="text-xl font-bold">Vehicle registry</h3><span class="text-slate-500 text-sm"><?=count($vehicles)?> visible vehicles</span></div><div class="overflow-x-auto"><table class="w-full text-sm text-left"><thead class="bg-slate-100"><tr><th class="p-3">Registration</th><th class="p-3">Type</th><th class="p-3">Capacity (kg)</th><th class="p-3">Status</th><th class="p-3">Details</th></tr></thead><tbody><?php foreach($vehicles as $v): ?><tr class="border-b"><td class="p-3 font-semibold"><?=h($v['registration_number'])?></td><td class="p-3"><?=h($v['vehicle_type'])?></td><td class="p-3"><?=h($v['capacity_kg'])?></td><td class="p-3"><?=h($v['status'])?></td><td class="p-3"><a class="text-blue-700 underline" href="?vehicle=<?=(int)$v['id']?>">Manage</a></td></tr><?php endforeach; ?></tbody></table></div>
<?php if($canManage): ?><details class="mt-4"><summary class="font-semibold cursor-pointer">Register a vehicle or trailer</summary><form method="post" class="grid md:grid-cols-5 gap-3 mt-3"><input type="hidden" name="_token" value="<?=h($token)?>"><input type="hidden" name="action" value="register"><input required maxlength="40" name="registration_number" placeholder="Registration" class="border rounded p-2"><select name="vehicle_type" class="border rounded p-2"><?php foreach(['bike','van','pickup','truck','trailer'] as $t): ?><option value="<?=h($t)?>"><?=h(ucfirst($t))?></option><?php endforeach; ?></select><input required type="number" step="0.01" min="0.01" name="capacity_kg" placeholder="Capacity kg" class="border rounded p-2"><select required name="office_id" class="border rounded p-2"><option value="">Select branch</option><?php foreach($offices as $o): ?><option value="<?=(int)$o['id']?>"><?=h($o['name'])?></option><?php endforeach; ?></select><button class="bg-blue-700 text-white rounded p-2">Register</button></form></details><?php endif; ?></section>
<?php if($selected): ?>
<section class="bg-white rounded-xl shadow-sm p-5"><div class="flex flex-wrap justify-between gap-3"><div><h3 class="text-2xl font-bold"><?=h($selected['registration_number'])?></h3><p class="text-slate-500"><?=h($selected['vehicle_type'])?> · <?=h($selected['status'])?></p></div><div class="text-sm rounded-lg p-3 <?= $safetyStatus['eligible']?'bg-emerald-50 text-emerald-800':'bg-red-50 text-red-800' ?>"><strong><?= $safetyStatus['eligible']?'Dispatch eligible':'Dispatch blocked' ?></strong><div><?=h(implode(', ',$safetyStatus['reasons']))?></div></div></div></section>
<?php
function sectionTitle(string $title): void { echo '<h3 class="text-xl font-bold mb-3">'.h($title).'</h3>'; }
function actionForm(string $action, string $token, int $vehicleId, string $children, string $label): void { echo '<form method="post" class="flex flex-wrap gap-2 items-end mt-3"><input type="hidden" name="_token" value="'.h($token).'"><input type="hidden" name="vehicle_id" value="'.$vehicleId.'"><input type="hidden" name="action" value="'.h($action).'">'.$children.'<button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-4 py-2 text-sm">'.h($label).'</button></form>'; }
function inp(string $name,string $placeholder,string $type='text',bool $required=false): string { return '<input class="border rounded-lg p-2 text-sm" name="'.h($name).'" type="'.h($type).'" '.($type==='number'?'step="any" ':'').'placeholder="'.h($placeholder).'" '.($required?'required':'').'>'; }
function dropdown(string $name,array $options): string { $s='<select class="border rounded-lg p-2 text-sm" name="'.h($name).'">'; foreach($options as $value=>$label) $s.='<option value="'.h($value).'">'.h($label).'</option>'; return $s.'</select>'; }
?>
<div class="grid lg:grid-cols-2 gap-5">
<section class="bg-white rounded-xl p-5 shadow-sm"><?php sectionTitle('Driver assignments'); ?><?php foreach($assignments as $a): ?><div class="py-2 border-b text-sm">Driver #<?=(int)$a['driver_user_id']?> · <?=h($a['assigned_at'])?> · <?= $a['ended_at']?'Released':'Active' ?><?php if($canManage && !$a['ended_at']): ?><?php actionForm('release_driver',$token,$vehicleId,'<input type="hidden" name="assignment_id" value="'.(int)$a['id'].'">','Release driver'); ?><?php endif; ?></div><?php endforeach; ?><?php if($canManage): ?><?php $options=[''=>'Select driver']; foreach($drivers as $d) $options[(string)$d['id']]=$d['name']; actionForm('assign',$token,$vehicleId,dropdown('driver_id',$options).inp('notes','Assignment notes'),'Assign driver'); ?><?php endif; ?></section>
<section class="bg-white rounded-xl p-5 shadow-sm"><?php sectionTitle('Odometer readings'); ?><?php foreach(array_slice($odometer,0,10) as $o): ?><div class="py-2 border-b text-sm"><strong><?=h($o['odometer_km'])?> km</strong> · <?=h($o['recorded_at'])?> · <?=h($o['notes'])?></div><?php endforeach; ?><?php if($canManage) actionForm('odometer',$token,$vehicleId,inp('odometer_km','Kilometres','number',true).inp('notes','Notes'),'Record reading'); ?></section>
<section class="bg-white rounded-xl p-5 shadow-sm"><?php sectionTitle('Maintenance schedules'); ?><?php foreach($schedules as $s): ?><div class="py-2 border-b text-sm"><strong><?=h($s['service_name'])?></strong> · <?=h($s['status'])?> · <?=h($s['next_due_date'])?><?php if($canManage): ?><?php actionForm('schedule_status',$token,$vehicleId,'<input type="hidden" name="schedule_id" value="'.(int)$s['id'].'">'.dropdown('status',['active'=>'Active','paused'=>'Paused','cancelled'=>'Cancelled']),'Update'); ?><?php endif; ?></div><?php endforeach; ?><?php if($canManage) actionForm('schedule',$token,$vehicleId,inp('service_name','Service name','text',true).inp('interval_km','Every km','number').inp('interval_days','Every days','number').inp('next_due_km','Next due km','number').inp('next_due_date','Next due date','date'),'Create schedule'); ?></section>
<section class="bg-white rounded-xl p-5 shadow-sm"><?php sectionTitle('Workshop jobs'); ?><?php foreach($jobs as $j): ?><div class="py-2 border-b text-sm"><strong>#<?=(int)$j['id']?> <?=h($j['job_type'])?></strong> · <?=h($j['priority'])?> · <?=h($j['status'])?><p><?=h($j['description'])?></p><?php if($canManage && !in_array($j['status'],['completed','cancelled'],true)): ?><?php actionForm('job_status',$token,$vehicleId,'<input type="hidden" name="job_id" value="'.(int)$j['id'].'">'.dropdown('status',['in_progress'=>'In progress','completed'=>'Completed','cancelled'=>'Cancelled']).inp('completion_notes','Completion notes'),'Update job'); ?><?php endif; ?></div><?php endforeach; ?><?php if($canManage) actionForm('job',$token,$vehicleId,dropdown('job_type',['service'=>'Service','inspection'=>'Inspection','repair'=>'Repair']).dropdown('priority',['normal'=>'Normal','low'=>'Low','high'=>'High','critical'=>'Critical']).inp('schedule_id','Schedule ID (optional)','number').inp('description','Description','text',true),'Open job'); ?></section>
<section class="bg-white rounded-xl p-5 shadow-sm"><?php sectionTitle('Defects'); ?><?php foreach($defects as $d): ?><div class="py-2 border-b text-sm"><strong><?=h($d['defect_title'])?></strong> · <?=h($d['severity'])?> · <?=h($d['status'])?><p><?=h($d['description'])?></p><?php if($canManage && $d['status']!=='resolved'): ?><?php actionForm('defect_status',$token,$vehicleId,'<input type="hidden" name="defect_id" value="'.(int)$d['id'].'">'.dropdown('status',['under_repair'=>'Under repair','resolved'=>'Resolved']).inp('resolution_notes','Resolution notes'),'Update defect'); ?><?php endif; ?></div><?php endforeach; ?><?php if($canManage) actionForm('defect',$token,$vehicleId,inp('defect_title','Defect title','text',true).dropdown('severity',['minor'=>'Minor','major'=>'Major','critical'=>'Critical']).inp('description','Description','text',true),'Report defect'); ?></section>
<section class="bg-white rounded-xl p-5 shadow-sm"><?php sectionTitle('Grounding & release'); ?><p class="text-sm mb-3">Current state: <strong><?= $safetyStatus['grounded']?'Grounded':'Not grounded' ?></strong></p><?php foreach(array_slice($events,0,10) as $ev): ?><div class="border-b py-2 text-sm"><?=h($ev['action'])?> · <?=h($ev['performed_at'])?> · <?=h($ev['reason'])?></div><?php endforeach; ?><?php if($canManage): ?><?php if(!$safetyStatus['grounded']) actionForm('ground',$token,$vehicleId,inp('reason','Grounding reason','text',true).inp('defect_id','Defect ID (optional)','number'),'Ground vehicle'); else actionForm('release',$token,$vehicleId,inp('reason','Release reason','text',true),'Release vehicle'); ?><?php endif; ?></section>
</div>
<?php else: ?><p class="text-slate-500">Select a vehicle from the registry to manage its operations.</p><?php endif; ?>
</main></body></html>
