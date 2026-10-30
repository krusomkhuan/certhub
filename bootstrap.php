<?php
declare(strict_types=1);
require_once __DIR__.'/version.php';
require_once __DIR__.'/database.php';
date_default_timezone_set('Asia/Bangkok');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: SAMEORIGIN');
try {
 $db=connectDatabase();
 try {$installed=$db->query("SELECT value FROM settings WHERE name='schema_version'")->fetchColumn();}
 catch(PDOException $ex) {if($ex->getCode()!=='42S02') throw $ex;$installed=false;}
 if($installed===false) initializeDatabase($db);
 elseif($installed==='mysql-1') migrateDatabase($db);
} catch(Throwable $ex) {
 if(PHP_SAPI==='cli') throw $ex;
 http_response_code(503);exit('ไม่สามารถเชื่อมต่อ MySQL กรุณาตรวจ config.php และการติดตั้งฐานข้อมูลตาม INSTALL.md');
}
$roles=['participant'=>'ผู้เข้าอบรม','speaker'=>'วิทยากร','committee'=>'คณะกรรมการดำเนินงาน','general'=>'ผู้รับเกียรติบัตรทั่วไป'];
$eventTypes=['training'=>'โครงการอบรม','activity'=>'กิจกรรม','competition'=>'การแข่งขัน','award'=>'การมอบรางวัล','other'=>'งานอื่น ๆ'];
function uploadImage(array $f): string {if($f['error']!==UPLOAD_ERR_OK || $f['size']>8*1024*1024) throw new RuntimeException('อัปโหลดภาพไม่สำเร็จ หรือไฟล์มีขนาดเกิน 8 MB');$mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;if(!$ext || !getimagesize($f['tmp_name'])) throw new RuntimeException('รองรับภาพ JPG, PNG และ WebP เท่านั้น');$name=bin2hex(random_bytes(20)).'.'.$ext;if(!move_uploaded_file($f['tmp_name'],__DIR__.'/data/'.$name)) throw new RuntimeException('บันทึกภาพไม่สำเร็จ');return $name;}
function e($v): string {return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function rows(string $sql,array $args=[]): array {global $db;$s=$db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);}
function run(string $sql,array $args=[]): void {global $db;$s=$db->prepare($sql);$s->execute($args);}
function csrf(): string {$_SESSION['csrf']??=bin2hex(random_bytes(32));return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';}
function admin(): bool {return isset($_SESSION['admin']);}
function go(string $url): never {header('Location: '.$url);exit;}
function thaiDate(string $d): string { $t=strtotime($d);return date('d/m/',$t).((int)date('Y',$t)+543); }

function setting(string $name,string $default=''): string {return rows('SELECT value FROM settings WHERE name=?',[$name])[0]['value']??$default;}
function saveSetting(string $name,string $value): void {run('INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$name,$value]);}
function numberingYear(): int {return (int)date('Y')+543;}
function certificateNumber(array $project,string $role,int $sequence,?int $year=null): string {
 return strtr(setting('central_pattern'),['{year}'=>(string)($year??numberingYear()),'{seq}'=>str_pad((string)$sequence,(int)setting('central_width'),'0',STR_PAD_LEFT)]);
}
// The central sequence restarts at 1 when the Buddhist year changes; central_next belongs to central_year only.
function nextSequence(int $year): int {
 $next=setting('central_year')===(string)$year?(int)setting('central_next'):1;
 return max($next,(int)rows('SELECT COALESCE(MAX(central_sequence),0)+1 n FROM recipients WHERE sequence_year=?',[$year])[0]['n']);
}
// Revoked numbers are reissued only within the year they belong to, lowest sequence first.
function releasedNumber(int $year): ?array {
 $first=null;
 foreach(rows("SELECT name,value FROM settings WHERE LEFT(name,16)='released_number_'") as $released) {
  $allocation=json_decode($released['value'],true,512,JSON_THROW_ON_ERROR);
  if((int)($allocation['year']??$year)===$year && (!$first || (int)$allocation['sequence']<(int)$first['sequence'])) $first=['name'=>$released['name'],'year'=>$year]+$allocation;
 }
 return $first;
}
function issueCertificates(int $projectId,bool $manageTransaction=true): void {
 global $db;
 if($manageTransaction) beginNumberingTransaction();
 try {
  $project=rows('SELECT * FROM projects WHERE id=?',[$projectId])[0];
  $year=numberingYear();$next=nextSequence($year);
  foreach(rows('SELECT * FROM recipients WHERE project_id=? AND certificate_number IS NULL ORDER BY id',[$projectId]) as $recipient) {
   // Numbers approved for a request stay with that work: named recipients take its unnamed placeholders first, in order.
   $reserved=$recipient['record_only']?null:(rows('SELECT r.id,r.certificate_number,r.central_sequence,r.sequence_year FROM recipients r WHERE r.project_id=? AND r.record_only=1 AND r.issued=1 AND r.certificate_number IS NOT NULL AND EXISTS(SELECT 1 FROM certificate_requests q WHERE q.project_id=r.project_id) ORDER BY r.sequence_year,r.central_sequence LIMIT 1',[$projectId])[0]??null);
   if($reserved) {
    run('DELETE FROM recipients WHERE id=?',[$reserved['id']]);
    run('UPDATE recipients SET certificate_number=?,central_sequence=?,sequence_year=?,issued_at=? WHERE id=?',[$reserved['certificate_number'],$reserved['central_sequence'],$reserved['sequence_year'],date(DATE_ATOM),$recipient['id']]);
    continue;
   }
   $released=releasedNumber($year);
   if($released) {$number=$released['number'];$sequence=$released['sequence'];}
   else {$sequence=$next++;$number=certificateNumber($project,$recipient['role'],$sequence,$year);}
   if(rows('SELECT id FROM recipients WHERE certificate_number=?',[$number]) || setting('retired_number_'.hash('sha256',$number))!=='') throw new RuntimeException('เลขที่ซ้ำกับทะเบียนเดิม: '.$number.' กรุณาเพิ่มลำดับถัดไปในทะเบียนกลาง');
   run('UPDATE recipients SET certificate_number=?,central_sequence=?,sequence_year=?,issued_at=? WHERE id=?',[$number,$sequence,$year,date(DATE_ATOM),$recipient['id']]);
   if($released) run('DELETE FROM settings WHERE name=?',[$released['name']]);
  }
  saveSetting('central_next',(string)$next);saveSetting('central_year',(string)$year);
  run('UPDATE recipients SET issued=1 WHERE project_id=?',[$projectId]);
  if($manageTransaction) $db->commit();
 } catch(Throwable $ex) {if($manageTransaction) $db->rollBack();throw $ex;}
}

function numberingStatus(): string {
 $last=rows('SELECT certificate_number FROM recipients WHERE certificate_number IS NOT NULL AND issued=1 ORDER BY sequence_year DESC,central_sequence DESC,issued_at DESC,id DESC LIMIT 1')[0]??null;
 $year=numberingYear();$released=releasedNumber($year);
 $nextNumber=$released?$released['number']:certificateNumber([],'general',nextSequence($year),$year);
 return '<div class="info" role="status"><strong>ขณะนี้ออกเลขถึง: '.e($last['certificate_number']??'ยังไม่มีเลขที่ออกในทะเบียน').'</strong><br>เลขที่จะออกถัดไป: <strong>'.e($nextNumber).'</strong>'.($released?' (เลขที่เพิกถอนและคืนไว้)':'').'<br><small>แสดงลำดับสูงสุดที่ยังใช้งานในทะเบียน ณ เวลาเปิดหน้านี้ ไม่ใช่เลขเริ่มต้นที่ตั้งไว้ เลขถัดไปอาจเปลี่ยนหากมีเจ้าหน้าที่ออกเลขพร้อมกัน</small></div>';
}
function projectDates(array $project): string {return thaiDate($project['date']).(($project['end_date']??$project['date'])!==$project['date']?' – '.thaiDate($project['end_date']):'');}
function fontControls(): string {
 return '<div class="font-controls" role="group" aria-label="ปรับขนาดตัวอักษร"><button type="button" data-font-action="decrease" aria-label="ลดขนาดตัวอักษร" title="ลดขนาดตัวอักษร">ก−</button><button type="button" data-font-action="reset" aria-label="คืนขนาดตัวอักษรเริ่มต้น" title="คืนค่าเริ่มต้น">ก</button><button type="button" data-font-action="increase" aria-label="เพิ่มขนาดตัวอักษร" title="เพิ่มขนาดตัวอักษร">ก+</button><output aria-live="polite" aria-atomic="true"><span class="sr-only">ขนาดตัวอักษร </span><span data-font-size>100%</span></output></div>';
}

$currentAdmin=admin()?(rows('SELECT * FROM admins WHERE id=?',[$_SESSION['admin']])[0]??null):null;
if(admin() && (!$currentAdmin || (int)($_SESSION['auth_version']??0)!==(int)$currentAdmin['auth_version'])) {
 unset($_SESSION['admin'],$_SESSION['auth_version']);$currentAdmin=null;
}

$adminRoles=['admin'=>'ผู้ดูแลระบบหลัก','registry'=>'เจ้าหน้าที่งานบริหารงานทั่วไป','report_viewer'=>'รองผู้อำนวยการฝ่ายบริหารทรัพยากร'];
function userRole(): string {global $currentAdmin;return $currentAdmin['role']??'';}
function canIssue(): bool {return admin() && in_array(userRole(),['admin','registry'],true);}
function accountHome(): string {return userRole()==='registry'?'register':(userRole()==='report_viewer'?'reports':'dashboard');}
