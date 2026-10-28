<?php
if(!isset($db) || !admin() || userRole()!=='admin'){http_response_code(403);exit;}
if(time()<($_SESSION['admin_retry_at']??0)) throw new RuntimeException('กรุณารอ 30 วินาทีแล้วลองใหม่');
if(!password_verify($_POST['current_password']??'',$currentAdmin['password'])) {
 $_SESSION['admin_failures']=($_SESSION['admin_failures']??0)+1;
 if($_SESSION['admin_failures']>=5) $_SESSION['admin_retry_at']=time()+30;
 throw new RuntimeException('รหัสผ่านปัจจุบันของคุณไม่ถูกต้อง');
}
unset($_SESSION['admin_failures'],$_SESSION['admin_retry_at']);
$display=trim($_POST['display_name']??'');$email=trim($_POST['email']??'');$username=trim($_POST['username']??'');$password=$_POST['new_password']??'';
if($display==='' || mb_strlen($display)>100) throw new RuntimeException('กรุณาระบุชื่อที่แสดงไม่เกิน 100 ตัวอักษร');
if(strlen($email)>254 || ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL))) throw new RuntimeException('รูปแบบอีเมลไม่ถูกต้อง');
if(mb_strlen($username)<3 || mb_strlen($username)>100 || preg_match('/[\x00-\x20\x7F]/u',$username)) throw new RuntimeException('ชื่อผู้ใช้ต้องยาว 3–100 ตัวอักษรและไม่มีช่องว่าง');
if(mb_strlen($password)<12 || strlen($password)>72) throw new RuntimeException('รหัสผ่านใหม่ต้องมีอย่างน้อย 12 ตัวอักษรและไม่เกิน 72 ไบต์');
if($password!==($_POST['confirm_password']??'')) throw new RuntimeException('ยืนยันรหัสผ่านบัญชีใหม่ไม่ตรงกัน');
if(rows('SELECT id FROM admins WHERE username=?',[$username])) throw new RuntimeException('ชื่อผู้ใช้นี้มีผู้ใช้งานแล้ว');
$role=$_POST['role']??'admin';if(!isset($adminRoles[$role])) throw new RuntimeException('บทบาทไม่ถูกต้อง');
try {run('INSERT INTO admins(username,password,display_name,email,role) VALUES(?,?,?,?,?)',[$username,password_hash($password,PASSWORD_DEFAULT),$display,$email,$role]);}
catch(PDOException $ex) {throw new RuntimeException('เพิ่มบัญชีไม่สำเร็จ ชื่อผู้ใช้อาจถูกใช้งานแล้ว กรุณาลองใหม่');}
$_SESSION['notice']='เพิ่มผู้ดูแลระบบสำรองแล้ว: '.$username;go('?page=admins');
