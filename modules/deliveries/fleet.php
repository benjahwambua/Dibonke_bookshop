<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
requireModulePermission('fleet','view');
$flash=$_SESSION['fleet_flash']??null;unset($_SESSION['fleet_flash']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$action=(string)($_POST['action']??'');
 try{
  if($action==='save_vehicle'){
   requireModulePermission('fleet',empty($_POST['id'])?'create':'edit');
   $id=(int)($_POST['id']??0);$reg=strtoupper(trim((string)($_POST['registration_number']??'')));
   $type=trim((string)($_POST['vehicle_type']??'Vehicle'));$name=trim((string)($_POST['vehicle_name']??''));$make=trim((string)($_POST['make_model']??''));$capacity=trim((string)($_POST['capacity_description']??''));$driver=(int)($_POST['assigned_driver_id']??0);$status=(string)($_POST['status']??'Active');$odo=max(0,(int)($_POST['current_odometer']??0));
   if($reg===''||$type==='')throw new RuntimeException('Registration number and vehicle type are required.');
   if(!in_array($status,['Active','Inactive','Maintenance'],true))throw new RuntimeException('Invalid vehicle status.');
   $data=[$reg,$name?:null,$type,$make?:null,$capacity?:null,$driver?:null,$_POST['insurance_expiry']?:null,$_POST['inspection_expiry']?:null,$odo,$status,trim((string)($_POST['notes']??''))?:null];
   if($id){$q=$conn->prepare('UPDATE fleet_vehicles SET registration_number=?,vehicle_name=?,vehicle_type=?,make_model=?,capacity_description=?,assigned_driver_id=?,insurance_expiry=?,inspection_expiry=?,current_odometer=?,status=?,notes=? WHERE id=?');$q->execute([...$data,$id]);}
   else{$q=$conn->prepare('INSERT INTO fleet_vehicles(registration_number,vehicle_name,vehicle_type,make_model,capacity_description,assigned_driver_id,insurance_expiry,inspection_expiry,current_odometer,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([...$data,(int)$_SESSION['user_id']]);}
   $_SESSION['fleet_flash']='Vehicle saved successfully.';
  }elseif($action==='maintenance'){
   requireModulePermission('fleet','create');$vehicle=(int)($_POST['vehicle_id']??0);$date=(string)($_POST['service_date']??'');$type=trim((string)($_POST['service_type']??''));$cost=max(0,(float)($_POST['cost']??0));
   if(!$vehicle||!$date||!$type)throw new RuntimeException('Vehicle, service date and service type are required.');
   $q=$conn->prepare('INSERT INTO fleet_maintenance(vehicle_id,service_date,service_type,odometer,cost,supplier,next_service_date,notes,recorded_by) VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$vehicle,$date,$type,($_POST['odometer']??'')!==''?(int)$_POST['odometer']:null,$cost,trim((string)($_POST['supplier']??''))?:null,$_POST['next_service_date']?:null,trim((string)($_POST['maintenance_notes']??''))?:null,(int)$_SESSION['user_id']]);
   if(!empty($_POST['mark_maintenance']))$conn->prepare("UPDATE fleet_vehicles SET status='Maintenance' WHERE id=?")->execute([$vehicle]);
   $_SESSION['fleet_flash']='Maintenance record saved.';
  }elseif($action==='delete_vehicle'){
   requireModulePermission('fleet','delete');$id=(int)($_POST['id']??0);
   $q=$conn->prepare('SELECT (SELECT COUNT(*) FROM deliveries WHERE vehicle_id=?) + (SELECT COUNT(*) FROM fleet_maintenance WHERE vehicle_id=?)');$q->execute([$id,$id]);
   if((int)$q->fetchColumn()>0)throw new RuntimeException('This vehicle has delivery or maintenance history. Set it Inactive instead of deleting it.');
   $conn->prepare('DELETE FROM fleet_vehicles WHERE id=?')->execute([$id]);$_SESSION['fleet_flash']='Vehicle deleted.';
  }
 }catch(Throwable $e){$_SESSION['fleet_flash']='Unable to complete action: '.$e->getMessage();}
 header('Location: fleet.php');exit;
}
$edit=null;if(isset($_GET['edit'])){$q=$conn->prepare('SELECT * FROM fleet_vehicles WHERE id=?');$q->execute([(int)$_GET['edit']]);$edit=$q->fetch()?:null;}
$vehicles=$conn->query("SELECT v.*,u.full_name driver_name,(SELECT COUNT(*) FROM deliveries d WHERE d.vehicle_id=v.id) delivery_count FROM fleet_vehicles v LEFT JOIN users u ON u.id=v.assigned_driver_id ORDER BY v.registration_number")->fetchAll();
$drivers=$conn->query("SELECT id,full_name FROM users WHERE status='Active' AND role='Driver' ORDER BY full_name")->fetchAll();
$maintenance=$conn->query("SELECT m.*,v.registration_number FROM fleet_maintenance m JOIN fleet_vehicles v ON v.id=m.vehicle_id ORDER BY m.service_date DESC,m.id DESC LIMIT 30")->fetchAll();
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content"><style>
.fleet-grid{display:grid;grid-template-columns:minmax(300px,390px) minmax(0,1fr);gap:18px}.fleet-card{background:#fff;border:1px solid #e7edf3;border-radius:13px;padding:20px;box-shadow:0 3px 14px #10263b0a;margin-bottom:18px}.fleet-form label{display:block;font-size:12px;font-weight:700;margin:12px 0 5px}.fleet-form input,.fleet-form select,.fleet-form textarea{box-sizing:border-box;width:100%;padding:10px;border:1px solid #dbe3ec;border-radius:8px}.fleet-btn{display:inline-block;border:0;border-radius:8px;background:#17324d;color:#fff;padding:10px 14px;font-weight:700;text-decoration:none;cursor:pointer}.fleet-muted{color:#718096;font-size:12px}.fleet-table{width:100%;border-collapse:collapse}.fleet-table th,.fleet-table td{padding:10px;border-bottom:1px solid #edf1f5;text-align:left;font-size:12px;white-space:nowrap}.fleet-table th{color:#526477;background:#f7f9fb}.fleet-status{font-weight:700}.fleet-actions{display:flex;gap:6px;align-items:center}.fleet-actions form{margin:0}@media(max-width:950px){.fleet-grid{grid-template-columns:1fr}}
</style>
<h1>Fleet Management</h1><p class="fleet-muted">Manage bookshop vehicles, assigned drivers, roadworthiness dates and service history.</p>
<?php if($flash):?><div class="fleet-card"><?=e($flash)?></div><?php endif;?>
<div style="display:flex;gap:9px;flex-wrap:wrap;margin:14px 0"><a class="fleet-btn" href="<?=BASE_URL?>/modules/deliveries/index.php">← Back to Deliveries</a><span class="fleet-muted" style="align-self:center">Vehicles: <?=count($vehicles)?> · Active: <?=count(array_filter($vehicles,fn($v)=>$v['status']==='Active'))?></span></div>
<div class="fleet-grid"><section>
<div class="fleet-card"><h3><?= $edit?'Edit Vehicle':'Add Vehicle' ?></h3><form class="fleet-form" method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_vehicle"><input type="hidden" name="id" value="<?=(int)($edit['id']??0)?>">
<label>Registration Number *</label><input name="registration_number" required maxlength="30" value="<?=e($edit['registration_number']??'')?>" placeholder="KDA 123A">
<label>Vehicle Name</label><input name="vehicle_name" maxlength="100" value="<?=e($edit['vehicle_name']??'')?>" placeholder="Delivery Van 1">
<label>Vehicle Type *</label><select name="vehicle_type"><?php foreach(['Van','Pickup','Motorcycle','Truck','Car','Other'] as $t):?><option <?=($edit['vehicle_type']??'Vehicle')===$t?'selected':''?>><?=e($t)?></option><?php endforeach;?></select>
<label>Make / Model</label><input name="make_model" value="<?=e($edit['make_model']??'')?>">
<label>Capacity</label><input name="capacity_description" value="<?=e($edit['capacity_description']??'')?>" placeholder="e.g. 1.5 tonnes / 12 boxes">
<label>Assigned Driver</label><select name="assigned_driver_id"><option value="">Not assigned</option><?php foreach($drivers as $d):?><option value="<?=$d['id']?>" <?=((int)($edit['assigned_driver_id']??0)===(int)$d['id'])?'selected':''?>><?=e($d['full_name'])?></option><?php endforeach;?></select>
<label>Insurance Expiry</label><input type="date" name="insurance_expiry" value="<?=e($edit['insurance_expiry']??'')?>">
<label>Inspection Expiry</label><input type="date" name="inspection_expiry" value="<?=e($edit['inspection_expiry']??'')?>">
<label>Current Odometer (km)</label><input type="number" min="0" name="current_odometer" value="<?=e((string)($edit['current_odometer']??0))?>">
<label>Status</label><select name="status"><?php foreach(['Active','Inactive','Maintenance'] as $s):?><option <?=$s===($edit['status']??'Active')?'selected':''?>><?=$s?></option><?php endforeach;?></select>
<label>Notes</label><textarea name="notes" rows="2"><?=e($edit['notes']??'')?></textarea><button class="fleet-btn" style="margin-top:14px"><?= $edit?'Save Changes':'Add Vehicle' ?></button><?php if($edit):?> <a href="fleet.php">Cancel</a><?php endif;?></form></div>
<div class="fleet-card"><h3>Record Maintenance / Service</h3><form class="fleet-form" method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="maintenance">
<label>Vehicle *</label><select name="vehicle_id" required><option value="">Select vehicle</option><?php foreach($vehicles as $v):?><option value="<?=$v['id']?>"><?=e($v['registration_number'])?></option><?php endforeach;?></select>
<label>Service Date *</label><input type="date" name="service_date" value="<?=date('Y-m-d')?>" required>
<label>Service Type *</label><input name="service_type" required placeholder="Oil change, tyres, inspection...">
<label>Odometer (km)</label><input type="number" min="0" name="odometer">
<label>Cost (KES)</label><input type="number" min="0" step="0.01" name="cost" value="0">
<label>Garage / Supplier</label><input name="supplier">
<label>Next Service Date</label><input type="date" name="next_service_date">
<label>Notes</label><textarea name="maintenance_notes" rows="2"></textarea><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="mark_maintenance" value="1" style="width:auto"> Mark vehicle as under maintenance</label><button class="fleet-btn" style="margin-top:12px">Save Service Record</button></form></div>
</section><section>
<div class="fleet-card"><h3>Vehicle Register</h3><div style="overflow:auto"><table class="fleet-table"><thead><tr><th>Vehicle</th><th>Type / Capacity</th><th>Driver</th><th>Insurance</th><th>Inspection</th><th>Odometer</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($vehicles as $v):?><tr><td><strong><?=e($v['registration_number'])?></strong><br><span class="fleet-muted"><?=e($v['vehicle_name']??$v['make_model']??'')?></span></td><td><?=e($v['vehicle_type'])?><br><?=e($v['capacity_description']??'')?></td><td><?=e($v['driver_name']??'Unassigned')?></td><td><?=e($v['insurance_expiry']??'—')?></td><td><?=e($v['inspection_expiry']??'—')?></td><td><?=number_format((int)$v['current_odometer'])?> km</td><td class="fleet-status"><?=e($v['status'])?></td><td><div class="fleet-actions"><a href="fleet.php?edit=<?=$v['id']?>">Edit</a><?php if(hasModulePermission('fleet','delete')):?><form method="post" onsubmit="return confirm('Delete this vehicle?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_vehicle"><input type="hidden" name="id" value="<?=$v['id']?>"><button type="submit" style="border:0;background:none;color:#b42318;cursor:pointer">Delete</button></form><?php endif;?></div></td></tr><?php endforeach;?><?php if(!$vehicles):?><tr><td colspan="8">No vehicles registered yet.</td></tr><?php endif;?></tbody></table></div></div>
<div class="fleet-card"><h3>Recent Maintenance History</h3><div style="overflow:auto"><table class="fleet-table"><thead><tr><th>Date</th><th>Vehicle</th><th>Service</th><th>Odometer</th><th>Cost</th><th>Next Service</th><th>Garage</th></tr></thead><tbody><?php foreach($maintenance as $m):?><tr><td><?=e($m['service_date'])?></td><td><?=e($m['registration_number'])?></td><td><?=e($m['service_type'])?></td><td><?=e($m['odometer']===null?'—':number_format((int)$m['odometer']).' km')?></td><td>KES <?=number_format((float)$m['cost'],2)?></td><td><?=e($m['next_service_date']??'—')?></td><td><?=e($m['supplier']??'—')?></td></tr><?php endforeach;?><?php if(!$maintenance):?><tr><td colspan="7">No service records yet.</td></tr><?php endif;?></tbody></table></div></div>
</section></div></main><?php require_once __DIR__.'/../../includes/footer.php';?>
