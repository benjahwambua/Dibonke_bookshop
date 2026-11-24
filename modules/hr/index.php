<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
requireModuleAccess('hr');
$tables=array_flip($conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
$required=['hr_leave_types','hr_leave_requests','hr_attendance','staff'];
$missing=array_values(array_filter($required,fn($t)=>!isset($tables[$t])));
$flash=$_SESSION['hr_flash']??'';unset($_SESSION['hr_flash']);
if(!$missing && $_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$action=(string)($_POST['action']??'');
 try{
  if($action==='leave'){
   requireModulePermission('hr','create');$staff=(int)($_POST['staff_id']??0);$type=(int)($_POST['leave_type_id']??0);$start=(string)($_POST['start_date']??'');$end=(string)($_POST['end_date']??'');
   $days=(strtotime($end)-strtotime($start))/86400+1;if($staff<1||$type<1||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)||$days<1||$days>366)throw new RuntimeException('Enter valid employee, leave type and date range.');
   $q=$conn->prepare("INSERT INTO hr_leave_requests(staff_id,leave_type_id,start_date,end_date,days_requested,reason,requested_by) VALUES(?,?,?,?,?,?,?)");
   $q->execute([$staff,$type,$start,$end,$days,trim((string)($_POST['reason']??''))?:null,(int)$_SESSION['user_id']]);$flash='Leave request submitted.';
  }elseif($action==='review'){
   requireModulePermission('hr','edit');$id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'');
   if(!in_array($status,['Approved','Rejected'],true))throw new RuntimeException('Invalid leave decision.');
   $q=$conn->prepare("UPDATE hr_leave_requests SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status='Pending'");$q->execute([$status,(int)$_SESSION['user_id'],$id]);if(!$q->rowCount())throw new RuntimeException('Pending leave request not found.');$flash='Leave request '.$status.'.';
  }elseif($action==='attendance'){
   requireModulePermission('hr','create');$staff=(int)($_POST['staff_id']??0);$date=(string)($_POST['attendance_date']??date('Y-m-d'));$status=(string)($_POST['attendance_status']??'Present');$in=trim((string)($_POST['check_in']??''));$out=trim((string)($_POST['check_out']??''));
   if($staff<1||!in_array($status,['Present','Absent','Late','Half Day','On Leave'],true))throw new RuntimeException('Enter a valid attendance record.');
   $q=$conn->prepare("INSERT INTO hr_attendance(staff_id,attendance_date,check_in,check_out,status,notes,recorded_by) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE check_in=VALUES(check_in),check_out=VALUES(check_out),status=VALUES(status),notes=VALUES(notes),recorded_by=VALUES(recorded_by)");
   $q->execute([$staff,$date,$in?:null,$out?:null,$status,trim((string)($_POST['notes']??''))?:null,(int)$_SESSION['user_id']]);$flash='Attendance saved.';
  }else throw new RuntimeException('Unknown HR action.');
 }catch(Throwable $e){$flash='HR action failed: '.$e->getMessage();}
 $_SESSION['hr_flash']=$flash;header('Location:index.php');exit;
}
$staff=[];$leaves=[];$attendance=[];$types=[];$active=0;$pending=0;
if(!$missing){
 $staff=$conn->query("SELECT id,employee_number,full_name,department,job_title,status,employment_type,hire_date FROM staff ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
 $active=(int)$conn->query("SELECT COUNT(*) FROM staff WHERE status='Active'")->fetchColumn();
 $pending=(int)$conn->query("SELECT COUNT(*) FROM hr_leave_requests WHERE status='Pending'")->fetchColumn();
 $types=$conn->query("SELECT id,leave_name FROM hr_leave_types WHERE status='Active' ORDER BY leave_name")->fetchAll(PDO::FETCH_ASSOC);
 $leaves=$conn->query("SELECT l.*,s.full_name,s.employee_number,t.leave_name FROM hr_leave_requests l JOIN staff s ON s.id=l.staff_id JOIN hr_leave_types t ON t.id=l.leave_type_id ORDER BY l.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
 $attendance=$conn->query("SELECT a.*,s.full_name,s.employee_number FROM hr_attendance a JOIN staff s ON s.id=a.staff_id ORDER BY a.attendance_date DESC,s.full_name LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
}
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content"><style>
.hr-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.hr-tabs a{padding:9px 13px;background:#eaf0f6;border-radius:8px;text-decoration:none;color:#17324d;font-size:12px;font-weight:800}.hr-panel{background:#fff;border:1px solid #e4eaf0;border-radius:12px;padding:18px;margin:14px 0}.hr-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.hr-stat{background:#fff;border:1px solid #e4eaf0;border-radius:12px;padding:17px}.hr-stat span{display:block;color:#64748b;font-size:11px;font-weight:800;text-transform:uppercase}.hr-stat strong{display:block;font-size:25px;color:#17324d;margin-top:6px}.hr-form{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.hr-form label{display:flex;flex-direction:column;gap:5px;font-size:11px;font-weight:800;color:#475569}.hr-form input,.hr-form select{padding:10px;border:1px solid #cbd5e1;border-radius:7px;min-width:145px}.hr-btn{background:#174a7e;color:white;border:0;border-radius:7px;padding:10px 13px;font-weight:800;cursor:pointer}.hr-table{width:100%;border-collapse:collapse}.hr-table th,.hr-table td{text-align:left;padding:9px;border-bottom:1px solid #edf1f5;font-size:12px}.hr-table th{background:#f3f6fa}.hr-muted{color:#64748b}.hr-flash{padding:12px;background:#eff6ff;border-radius:8px;margin:12px 0}@media(max-width:850px){.hr-stats{grid-template-columns:1fr}.hr-panel{overflow:auto}}
</style>
<h1>Human Resources</h1><p class="hr-muted">Employee records, attendance, leave administration and workforce oversight.</p>
<?php if($flash):?><div class="hr-flash"><?=e($flash)?></div><?php endif;?>
<?php if($missing):?><section class="hr-panel"><h2>HR database setup required</h2><p>Import <code>database/dibonke_accounting_hr.sql</code> after pulling this branch.</p><p class="hr-muted">Missing: <?=e(implode(', ',$missing))?></p></section>
<?php else:?>
<section class="hr-stats"><div class="hr-stat"><span>Active employees</span><strong><?=$active?></strong></div><div class="hr-stat"><span>Pending leave requests</span><strong><?=$pending?></strong></div><div class="hr-stat"><span>Staff records</span><strong><?=count($staff)?></strong></div></section>
<nav class="hr-tabs"><a href="#employees">Employees</a><a href="#leave">Leave management</a><a href="#attendance">Attendance</a></nav>
<section class="hr-panel" id="employees"><h2>Employee directory</h2><p class="hr-muted">Employee profiles remain linked to the existing Staff Directory and user login accounts.</p><div style="overflow:auto"><table class="hr-table"><thead><tr><th>Employee No.</th><th>Name</th><th>Department</th><th>Job title</th><th>Type</th><th>Hire date</th><th>Status</th></tr></thead><tbody><?php foreach($staff as $s):?><tr><td><?=e($s['employee_number'])?></td><td><?=e($s['full_name'])?></td><td><?=e($s['department']??'—')?></td><td><?=e($s['job_title']??'—')?></td><td><?=e($s['employment_type'])?></td><td><?=e($s['hire_date']??'—')?></td><td><?=e($s['status'])?></td></tr><?php endforeach;?></tbody></table></div><p><a href="<?=BASE_URL?>/modules/staff/index.php">Manage employee profiles</a></p></section>
<section class="hr-panel" id="leave"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2>Submit leave request</h2><?php if(hasModulePermission('hr','create')||hasModulePermission('hr','edit')):?><a href="<?=BASE_URL?>/modules/hr/leave_types.php">Manage Leave Types</a><?php endif;?></div><?php if(hasModulePermission('hr','create')):?><form method="post" class="hr-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="leave"><label>Employee<select name="staff_id" required><option value="">Select employee</option><?php foreach($staff as $s):if($s['status']==='Active'):?><option value="<?=(int)$s['id']?>"><?=e($s['employee_number'].' — '.$s['full_name'])?></option><?php endif;endforeach;?></select></label><label>Leave type<select name="leave_type_id" required><?php foreach($types as $t):?><option value="<?=(int)$t['id']?>"><?=e($t['leave_name'])?></option><?php endforeach;?></select></label><label>Start date<input type="date" name="start_date" required></label><label>End date<input type="date" name="end_date" required></label><label>Reason<input name="reason" maxlength="1000"></label><button class="hr-btn">Submit request</button></form><?php endif;?>
<h3>Leave register</h3><div style="overflow:auto"><table class="hr-table"><thead><tr><th>Employee</th><th>Leave</th><th>Dates</th><th>Days</th><th>Reason</th><th>Status</th><th>Decision</th></tr></thead><tbody><?php foreach($leaves as $l):?><tr><td><?=e($l['full_name'])?></td><td><?=e($l['leave_name'])?></td><td><?=e($l['start_date'].' to '.$l['end_date'])?></td><td><?=e($l['days_requested'])?></td><td><?=e($l['reason']??'—')?></td><td><?=e($l['status'])?></td><td><?php if($l['status']==='Pending'&&hasModulePermission('hr','edit')):?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?=(int)$l['id']?>"><button class="hr-btn" name="status" value="Approved">Approve</button> <button class="hr-btn" name="status" value="Rejected">Reject</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="hr-panel" id="attendance"><h2>Daily attendance</h2><?php if(hasModulePermission('hr','create')):?><form method="post" class="hr-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="attendance"><label>Employee<select name="staff_id" required><option value="">Select employee</option><?php foreach($staff as $s):if($s['status']==='Active'):?><option value="<?=(int)$s['id']?>"><?=e($s['full_name'])?></option><?php endif;endforeach;?></select></label><label>Date<input type="date" name="attendance_date" value="<?=date('Y-m-d')?>" required></label><label>Status<select name="attendance_status"><?php foreach(['Present','Absent','Late','Half Day','On Leave'] as $v):?><option><?=e($v)?></option><?php endforeach;?></select></label><label>Check in<input type="time" name="check_in"></label><label>Check out<input type="time" name="check_out"></label><label>Notes<input name="notes" maxlength="500"></label><button class="hr-btn">Save attendance</button></form><?php endif;?>
<h3>Attendance register</h3><div style="overflow:auto"><table class="hr-table"><thead><tr><th>Date</th><th>Employee</th><th>Check in</th><th>Check out</th><th>Status</th><th>Notes</th></tr></thead><tbody><?php foreach($attendance as $a):?><tr><td><?=e($a['attendance_date'])?></td><td><?=e($a['full_name'])?></td><td><?=e($a['check_in']??'—')?></td><td><?=e($a['check_out']??'—')?></td><td><?=e($a['status'])?></td><td><?=e($a['notes']??'')?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php endif;?></main><?php require_once __DIR__.'/../../includes/footer.php';?>
