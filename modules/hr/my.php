<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
requireModuleAccess('hr_self_service');

$userId=(int)$_SESSION['user_id'];
$q=$conn->prepare("SELECT id,employee_number,full_name,department,job_title,status FROM staff WHERE user_id=? LIMIT 1");
$q->execute([$userId]);$employee=$q->fetch(PDO::FETCH_ASSOC);
$flash=$_SESSION['hr_self_flash']??'';unset($_SESSION['hr_self_flash']);
$today=date('Y-m-d');
if($employee && $_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$action=(string)($_POST['action']??'');
 try{
  if($employee['status']!=='Active')throw new RuntimeException('Your employee profile is not active. Please contact HR.');
  if($action==='leave'){
   requireModulePermission('hr_self_service','create');
   $type=(int)($_POST['leave_type_id']??0);$start=trim((string)($_POST['start_date']??''));$end=trim((string)($_POST['end_date']??''));$reason=trim((string)($_POST['reason']??''));
   $startDate=DateTimeImmutable::createFromFormat('!Y-m-d',$start);$endDate=DateTimeImmutable::createFromFormat('!Y-m-d',$end);
   if(!$startDate||!$endDate||$startDate->format('Y-m-d')!==$start||$endDate->format('Y-m-d')!==$end||$endDate<$startDate||$startDate<new DateTimeImmutable('today'))throw new RuntimeException('Enter a valid future leave date range.');
   if((int)$startDate->diff($endDate)->days>366)throw new RuntimeException('A single leave request cannot exceed 366 calendar days.');
   $days=0;for($d=$startDate;$d<=$endDate;$d=$d->modify('+1 day'))if((int)$d->format('N')<6)$days++;
   if($days<1||$days>366)throw new RuntimeException('The selected range must contain at least one working day.');
   $t=$conn->prepare("SELECT id FROM hr_leave_types WHERE id=? AND status='Active'");$t->execute([$type]);if(!$t->fetchColumn())throw new RuntimeException('Select an active leave type.');
   $overlap=$conn->prepare("SELECT COUNT(*) FROM hr_leave_requests WHERE staff_id=? AND status IN ('Pending','Approved') AND start_date<=? AND end_date>=?");
   $overlap->execute([(int)$employee['id'],$end,$start]);if((int)$overlap->fetchColumn()>0)throw new RuntimeException('You already have a pending or approved leave request overlapping these dates.');
   $ins=$conn->prepare("INSERT INTO hr_leave_requests(staff_id,leave_type_id,start_date,end_date,days_requested,reason,requested_by) VALUES(?,?,?,?,?,?,?)");
   $ins->execute([(int)$employee['id'],$type,$start,$end,$days,$reason?:null,$userId]);$flash='Your leave request has been submitted for review.';
  }elseif($action==='cancel_leave'){
   $id=(int)($_POST['id']??0);$cancel=$conn->prepare("UPDATE hr_leave_requests SET status='Cancelled' WHERE id=? AND staff_id=? AND status='Pending'");
   $cancel->execute([$id,(int)$employee['id']]);if(!$cancel->rowCount())throw new RuntimeException('Only your own pending leave request can be cancelled.');$flash='Your pending leave request was cancelled.';
  }elseif($action==='clock_in'||$action==='clock_out'){
   requireModulePermission('hr_self_service','create');
   $conn->beginTransaction();
   $lock=$conn->prepare("SELECT id,check_in,check_out FROM hr_attendance WHERE staff_id=? AND attendance_date=CURDATE() FOR UPDATE");
   $lock->execute([(int)$employee['id']]);$row=$lock->fetch(PDO::FETCH_ASSOC);
   if($action==='clock_in'){
    if($row && $row['check_in']!==null)throw new RuntimeException('You have already clocked in today.');
    if($row){$up=$conn->prepare("UPDATE hr_attendance SET check_in=CURTIME(),status='Present',recorded_by=? WHERE id=? AND check_in IS NULL");$up->execute([$userId,(int)$row['id']]);}
    else{$up=$conn->prepare("INSERT INTO hr_attendance(staff_id,attendance_date,check_in,status,recorded_by) VALUES(?,CURDATE(),CURTIME(),'Present',?)");$up->execute([(int)$employee['id'],$userId]);}
    if(!$up->rowCount())throw new RuntimeException('Clock-in was not recorded. Refresh and try again.');
    $flash='Clock-in recorded at '.date('H:i:s').'.';
   }else{
    if(!$row||$row['check_in']===null)throw new RuntimeException('Clock in before clocking out.');
    if($row['check_out']!==null)throw new RuntimeException('You have already clocked out today.');
    $up=$conn->prepare("UPDATE hr_attendance SET check_out=CURTIME(),recorded_by=? WHERE id=? AND check_out IS NULL");$up->execute([$userId,(int)$row['id']]);
    if(!$up->rowCount())throw new RuntimeException('Clock-out was not recorded. Refresh and try again.');
    $flash='Clock-out recorded at '.date('H:i:s').'.';
   }
   $conn->commit();
  }else throw new RuntimeException('Unknown HR action.');
 }catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();$flash='Unable to complete action: '.$e->getMessage();}
 $_SESSION['hr_self_flash']=$flash;header('Location:my.php');exit;
}
$types=$employee?$conn->query("SELECT id,leave_name,annual_days,is_paid FROM hr_leave_types WHERE status='Active' ORDER BY leave_name")->fetchAll(PDO::FETCH_ASSOC):[];
$leaves=[];$attendance=[];$todayRow=null;
if($employee){
 $l=$conn->prepare("SELECT l.*,t.leave_name FROM hr_leave_requests l JOIN hr_leave_types t ON t.id=l.leave_type_id WHERE l.staff_id=? ORDER BY l.id DESC LIMIT 50");$l->execute([(int)$employee['id']]);$leaves=$l->fetchAll(PDO::FETCH_ASSOC);
 $a=$conn->prepare("SELECT attendance_date,check_in,check_out,status FROM hr_attendance WHERE staff_id=? ORDER BY attendance_date DESC LIMIT 60");$a->execute([(int)$employee['id']]);$attendance=$a->fetchAll(PDO::FETCH_ASSOC);
 $a=$conn->prepare("SELECT id,check_in,check_out,status FROM hr_attendance WHERE staff_id=? AND attendance_date=CURDATE() LIMIT 1");$a->execute([(int)$employee['id']]);$todayRow=$a->fetch(PDO::FETCH_ASSOC)?:null;
}
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<style>
.self-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.self-panel{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:18px;margin:14px 0}.self-muted{color:#64748b}.self-form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.self-form label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;color:#475569}.self-form input,.self-form select{padding:10px;border:1px solid #cbd5e1;border-radius:7px}.self-btn{background:#174a7e;color:#fff;border:0;border-radius:7px;padding:10px 14px;font-weight:800;cursor:pointer}.self-btn:disabled{opacity:.45;cursor:not-allowed}.self-table{width:100%;border-collapse:collapse}.self-table th,.self-table td{text-align:left;padding:10px;border-bottom:1px solid #edf1f5;font-size:12px}.self-table th{background:#f3f6fa}.self-flash{background:#eff6ff;padding:12px;border-radius:8px;margin:12px 0}.self-actions{display:flex;gap:8px;flex-wrap:wrap}@media(max-width:800px){.self-grid{grid-template-columns:1fr}.self-panel{overflow:auto}}
</style>
<h1>My HR Self-Service</h1><p class="self-muted">Your attendance, leave requests and employee records.</p>
<?php if($flash):?><div class="self-flash"><?=e($flash)?></div><?php endif;?>
<?php if(!$employee):?>
<section class="self-panel"><h2>Employee profile not linked</h2><p>Your login account is not yet linked to an active staff profile. HR/Admin must link your account in User Management before you can clock in/out or request leave.</p><p><a href="<?=BASE_URL?>/modules/hr/index.php">HR workspace</a> (for authorized HR users)</p></section>
<?php else:?>
<section class="self-panel"><h2><?=e($employee['full_name'])?></h2><p class="self-muted"><?=e($employee['employee_number'])?> · <?=e($employee['department']??'') ?> · <?=e($employee['job_title']??'')?></p><p><strong>Today:</strong> <?=e(date('D, d M Y'))?> &nbsp; <strong>Clock in:</strong> <?=e($todayRow['check_in']??'Not recorded')?> &nbsp; <strong>Clock out:</strong> <?=e($todayRow['check_out']??'Not recorded')?></p>
<form method="post" class="self-actions"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button class="self-btn" name="action" value="clock_in" <?=($todayRow&&$todayRow['check_in'])?'disabled':''?>>Clock In</button><button class="self-btn" name="action" value="clock_out" <?=(!$todayRow||!$todayRow['check_in']||$todayRow['check_out'])?'disabled':''?>>Clock Out</button></form></section>
<div class="self-grid"><section class="self-panel"><h2>Request Leave</h2><form method="post" class="self-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="leave"><label>Leave type<select name="leave_type_id" required><option value="">Select leave type</option><?php foreach($types as $t):?><option value="<?=(int)$t['id']?>"><?=e($t['leave_name'])?><?=((float)$t['annual_days']>0?' ('.number_format((float)$t['annual_days'],0).' days)':'')?></option><?php endforeach;?></select></label><label>From<input type="date" name="start_date" min="<?=e($today)?>" required></label><label>To<input type="date" name="end_date" min="<?=e($today)?>" required></label><label style="min-width:100%;flex:1">Reason<input name="reason" maxlength="1000" placeholder="Reason for leave"></label><button class="self-btn">Submit Request</button></form></section>
<section class="self-panel"><h2>My Leave Requests</h2><div style="overflow:auto"><table class="self-table"><thead><tr><th>Leave</th><th>Dates</th><th>Days</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($leaves as $l):?><tr><td><?=e($l['leave_name'])?></td><td><?=e($l['start_date'].' – '.$l['end_date'])?></td><td><?=e($l['days_requested'])?></td><td><?=e($l['status'])?></td><td><?php if($l['status']==='Pending'):?><form method="post" onsubmit="return confirm('Cancel this leave request?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="cancel_leave"><input type="hidden" name="id" value="<?=(int)$l['id']?>"><button class="self-btn" type="submit">Cancel</button></form><?php endif;?></td></tr><?php endforeach;?><?php if(!$leaves):?><tr><td colspan="5">No leave requests yet.</td></tr><?php endif;?></tbody></table></div></section></div>
<section class="self-panel"><h2>My Attendance History</h2><div style="overflow:auto"><table class="self-table"><thead><tr><th>Date</th><th>Clock In</th><th>Clock Out</th><th>Status</th></tr></thead><tbody><?php foreach($attendance as $a):?><tr><td><?=e($a['attendance_date'])?></td><td><?=e($a['check_in']??'—')?></td><td><?=e($a['check_out']??'—')?></td><td><?=e($a['status'])?></td></tr><?php endforeach;?><?php if(!$attendance):?><tr><td colspan="4">No attendance records yet.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?></main>
<?php require_once __DIR__.'/../../includes/footer.php';?>
