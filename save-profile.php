<?php
if(!isset($db) || !admin()){http_response_code(403);exit;}
$display=trim($_POST['display_name']??'');$email=trim($_POST['email']??'');$username=trim($_POST['username']??'');
$password=$_POST['new_password']??'';$confirm=$_POST['confirm_password']??'';
if($display==='' || mb_strlen($display)>100) throw new RuntimeException('กรุณาระบุชื่อที่แสดงไม่เกิน 100 ตัวอักษร');
if(strlen($email)>254 || ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL))) throw new RuntimeException('รูปแบบอีเมลไม่ถูกต้อง');
if(mb_strlen($username)<3 || mb_strlen($username)>100 || preg_match('/[\x00-\x20\x7F]/u',$username)) throw new RuntimeException('ชื่อผู้ใช้ต้องยาว 3–100 ตัวอักษรและไม่มีช่องว่าง');
$changingCredentials=$username!==$currentAdmin['username'] || $password!=='';
if($changingCredentials) {
 if(time()<($_SESSION['profile_retry_at']??0)) throw new RuntimeException('กรุณารอ 30 วินาทีก่อนยืนยันรหัสผ่านอีกครั้ง');
 if(!password_verify($_POST['current_password']??'',$currentAdmin['password'])) {
  $_SESSION['profile_failures']=($_SESSION['profile_failures']??0)+1;
  if($_SESSION['profile_failures']>=5) $_SESSION['profile_retry_at']=time()+30;
  throw new RuntimeException('รหัสผ่านปัจจุบันไม่ถูกต้อง');
 }
 unset($_SESSION['profile_retry_at'],$_SESSION['profile_failures']);
}
if($password!==$confirm) throw new RuntimeException('รหัสผ่านใหม่และการยืนยันไม่ตรงกัน');
if($password!=='' && (mb_strlen($password)<12 || strlen($password)>72)) throw new RuntimeException('รหัสผ่านใหม่ต้องมีอย่างน้อย 12 ตัวอักษรและไม่เกิน 72 ไบต์');
if(rows('SELECT id FROM admins WHERE username=? AND id<>?',[$username,$currentAdmin['id']])) throw new RuntimeException('ชื่อผู้ใช้นี้มีผู้ใช้งานแล้ว');
$version=(int)$currentAdmin['auth_version']+($changingCredentials?1:0);
try {
 run('UPDATE admins SET display_name=?,email=?,username=?,password=?,auth_version=? WHERE id=?',[$display,$email,$username,$password!==''?password_hash($password,PASSWORD_DEFAULT):$currentAdmin['password'],$version,$currentAdmin['id']]);
} catch(PDOException $ex) {throw new RuntimeException('บันทึกไม่สำเร็จ ชื่อผู้ใช้อาจถูกใช้งานแล้ว กรุณาลองใหม่');}
if($changingCredentials) {session_regenerate_id(true);$_SESSION['auth_version']=$version;$_SESSION['csrf']=bin2hex(random_bytes(32));}
$_SESSION['notice']='บันทึกโปรไฟล์แล้ว';go('?page=profile');
