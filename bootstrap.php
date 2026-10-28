<?php
declare(strict_types=1);
require_once __DIR__.'/version.php';
date_default_timezone_set('Asia/Bangkok');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
$db = new PDO('sqlite:'.__DIR__.'/data/certhub.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA busy_timeout=10000');
$db->exec('CREATE TABLE IF NOT EXISTS admins(id INTEGER PRIMARY KEY, username TEXT UNIQUE, password TEXT); CREATE TABLE IF NOT EXISTS projects(id INTEGER PRIMARY KEY, title TEXT NOT NULL, date TEXT NOT NULL, location TEXT NOT NULL, description TEXT NOT NULL DEFAULT "", published INTEGER NOT NULL DEFAULT 0); CREATE TABLE IF NOT EXISTS templates(id INTEGER PRIMARY KEY, project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE, role TEXT NOT NULL, heading TEXT NOT NULL, signer TEXT NOT NULL DEFAULT "", position TEXT NOT NULL DEFAULT "", color TEXT NOT NULL DEFAULT "#235d50", canva TEXT NOT NULL DEFAULT "", background TEXT, UNIQUE(project_id,role)); CREATE TABLE IF NOT EXISTS recipients(id INTEGER PRIMARY KEY, project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE, name TEXT NOT NULL, organization TEXT NOT NULL DEFAULT "", role TEXT NOT NULL, token TEXT UNIQUE NOT NULL, issued INTEGER NOT NULL DEFAULT 0, UNIQUE(project_id,name,role));');
$roles=['participant'=>'ผู้เข้าอบรม','speaker'=>'วิทยากร','committee'=>'คณะกรรมการดำเนินงาน','general'=>'ผู้รับเกียรติบัตรทั่วไป'];
$columns=array_column($db->query('PRAGMA table_info(projects)')->fetchAll(PDO::FETCH_ASSOC),'name');
foreach (['owner','objective','results','problems','suggestions'] as $column) {if(!in_array($column,$columns,true)) $db->exec("ALTER TABLE projects ADD COLUMN $column TEXT NOT NULL DEFAULT ''");}
$db->exec('CREATE TABLE IF NOT EXISTS photos(id INTEGER PRIMARY KEY, project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE, filename TEXT NOT NULL, caption TEXT NOT NULL DEFAULT "")');
function uploadImage(array $f): string {if($f['error']!==UPLOAD_ERR_OK || $f['size']>8*1024*1024) throw new RuntimeException('อัปโหลดภาพไม่สำเร็จ หรือไฟล์มีขนาดเกิน 8 MB');$mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;if(!$ext || !getimagesize($f['tmp_name'])) throw new RuntimeException('รองรับภาพ JPG, PNG และ WebP เท่านั้น');$name=bin2hex(random_bytes(20)).'.'.$ext;if(!move_uploaded_file($f['tmp_name'],__DIR__.'/data/'.$name)) throw new RuntimeException('บันทึกภาพไม่สำเร็จ');return $name;}
function e($v): string {return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function rows(string $sql,array $args=[]): array {global $db;$s=$db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);}
function run(string $sql,array $args=[]): void {global $db;$s=$db->prepare($sql);$s->execute($args);}
function csrf(): string {$_SESSION['csrf']??=bin2hex(random_bytes(32));return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';}
function admin(): bool {return isset($_SESSION['admin']);}
function go(string $url): never {header('Location: '.$url);exit;}
function thaiDate(string $d): string { $t=strtotime($d);return date('d/m/',$t).((int)date('Y',$t)+543); }

// Additive migrations preserve existing certificate numbers.
foreach (['number_pattern'=>"TEXT NOT NULL DEFAULT 'CH-{project}-{seq}'",'number_next'=>'INTEGER NOT NULL DEFAULT 1','number_width'=>'INTEGER NOT NULL DEFAULT 4'] as $column=>$definition) {
 if(!in_array($column,$columns,true)) $db->exec("ALTER TABLE projects ADD COLUMN $column $definition");
}
$recipientColumns=array_column($db->query('PRAGMA table_info(recipients)')->fetchAll(PDO::FETCH_ASSOC),'name');
if(!in_array('certificate_number',$recipientColumns,true)) {
 $db->beginTransaction();
 $db->exec('ALTER TABLE recipients ADD COLUMN certificate_number TEXT');
 $db->exec("UPDATE recipients SET certificate_number='CH-' || printf('%06d',id) WHERE issued=1");
 $db->commit();
}
$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS certificate_number_unique ON recipients(certificate_number); CREATE TABLE IF NOT EXISTS settings(name TEXT PRIMARY KEY,value TEXT NOT NULL)');
function setting(string $name,string $default=''): string {return rows('SELECT value FROM settings WHERE name=?',[$name])[0]['value']??$default;}
function saveSetting(string $name,string $value): void {run('INSERT INTO settings(name,value) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value',[$name,$value]);}
$eventTypes=['training'=>'โครงการอบรม','activity'=>'กิจกรรม','competition'=>'การแข่งขัน','award'=>'การมอบรางวัล','other'=>'งานอื่น ๆ'];
if(!in_array('event_type',$columns,true)) $db->exec("ALTER TABLE projects ADD COLUMN event_type TEXT NOT NULL DEFAULT 'training'");
foreach(['central_sequence'=>'INTEGER','issued_at'=>'TEXT','record_only'=>'INTEGER NOT NULL DEFAULT 0'] as $column=>$definition) if(!in_array($column,$recipientColumns,true)) $db->exec("ALTER TABLE recipients ADD COLUMN $column $definition");
$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS central_sequence_unique ON recipients(central_sequence)');
$db->exec("CREATE TABLE IF NOT EXISTS certificate_requests(id INTEGER PRIMARY KEY, token TEXT NOT NULL UNIQUE, requester TEXT NOT NULL, department TEXT NOT NULL, contact TEXT NOT NULL, title TEXT NOT NULL, event_type TEXT NOT NULL, event_date TEXT NOT NULL, purpose TEXT NOT NULL, names TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', submitted_at TEXT NOT NULL, reviewed_at TEXT, reviewed_by INTEGER, review_note TEXT NOT NULL DEFAULT '', project_id INTEGER REFERENCES projects(id))");
$requestColumns=array_column($db->query('PRAGMA table_info(certificate_requests)')->fetchAll(PDO::FETCH_ASSOC),'name');
if(!in_array('quantity',$requestColumns,true)) {
 $db->exec('ALTER TABLE certificate_requests ADD COLUMN quantity INTEGER NOT NULL DEFAULT 1');
 foreach(rows('SELECT id,names FROM certificate_requests') as $legacyRequest) run('UPDATE certificate_requests SET quantity=? WHERE id=?',[max(1,count(json_decode($legacyRequest['names'],true)?:[])),$legacyRequest['id']]);
}
foreach(['central_pattern'=>'วอ.โพนทอง/{year}/{seq}','central_next'=>'1','central_width'=>'5'] as $key=>$value) run('INSERT OR IGNORE INTO settings(name,value) VALUES(?,?)',[$key,$value]);
// Existing work gets a general-purpose template without changing issued documents.
$db->exec("INSERT OR IGNORE INTO templates(project_id,role,heading) SELECT p.id,'general','เพื่อแสดงว่าได้รับเกียรติบัตร' FROM projects p WHERE NOT EXISTS(SELECT 1 FROM recipients r WHERE r.project_id=p.id AND r.record_only=1)");
function certificateNumber(array $project,string $role,int $sequence): string {
 return strtr(setting('central_pattern'),['{year}'=>(string)((int)date('Y')+543),'{seq}'=>str_pad((string)$sequence,(int)setting('central_width'),'0',STR_PAD_LEFT)]);
}
function issueCertificates(int $projectId,bool $manageTransaction=true): void {
 global $db;
 if($manageTransaction) $db->exec('BEGIN IMMEDIATE');
 try {
  $project=rows('SELECT * FROM projects WHERE id=?',[$projectId])[0];
  $next=max((int)setting('central_next'),(int)rows('SELECT COALESCE(MAX(central_sequence),0)+1 n FROM recipients')[0]['n']);
  foreach(rows('SELECT * FROM recipients WHERE project_id=? AND certificate_number IS NULL ORDER BY id',[$projectId]) as $recipient) {
   $number=certificateNumber($project,$recipient['role'],$next);
   if(rows('SELECT id FROM recipients WHERE certificate_number=?',[$number])) throw new RuntimeException('เลขที่ซ้ำกับทะเบียนเดิม: '.$number.' กรุณาเพิ่มลำดับถัดไปในทะเบียนกลาง');
   run('UPDATE recipients SET certificate_number=?,central_sequence=?,issued_at=? WHERE id=?',[$number,$next++,date(DATE_ATOM),$recipient['id']]);
  }
  saveSetting('central_next',(string)$next);
  run('UPDATE recipients SET issued=1 WHERE project_id=?',[$projectId]);
  if($manageTransaction) $db->exec('COMMIT');
 } catch(Throwable $ex) {if($manageTransaction) $db->exec('ROLLBACK');throw $ex;}
}

if(!in_array('end_date',$columns,true)) { $db->exec("ALTER TABLE projects ADD COLUMN end_date TEXT NOT NULL DEFAULT ''"); $db->exec('UPDATE projects SET end_date=date'); }
function projectDates(array $project): string {return thaiDate($project['date']).(($project['end_date']??$project['date'])!==$project['date']?' – '.thaiDate($project['end_date']):'');}

function fontControls(): string {
 return '<div class="font-controls" role="group" aria-label="ปรับขนาดตัวอักษร"><button type="button" data-font-action="decrease" aria-label="ลดขนาดตัวอักษร" title="ลดขนาดตัวอักษร">ก−</button><button type="button" data-font-action="reset" aria-label="คืนขนาดตัวอักษรเริ่มต้น" title="คืนค่าเริ่มต้น">ก</button><button type="button" data-font-action="increase" aria-label="เพิ่มขนาดตัวอักษร" title="เพิ่มขนาดตัวอักษร">ก+</button><output aria-live="polite" aria-atomic="true"><span class="sr-only">ขนาดตัวอักษร </span><span data-font-size>100%</span></output></div>';
}

$adminColumns=array_column($db->query('PRAGMA table_info(admins)')->fetchAll(PDO::FETCH_ASSOC),'name');
foreach(['display_name'=>"TEXT NOT NULL DEFAULT ''",'email'=>"TEXT NOT NULL DEFAULT ''",'auth_version'=>'INTEGER NOT NULL DEFAULT 0','role'=>"TEXT NOT NULL DEFAULT 'admin'"] as $column=>$definition) {
 if(!in_array($column,$adminColumns,true)) $db->exec("ALTER TABLE admins ADD COLUMN $column $definition");
}
$currentAdmin=admin()?(rows('SELECT * FROM admins WHERE id=?',[$_SESSION['admin']])[0]??null):null;
if(admin() && (!$currentAdmin || (int)($_SESSION['auth_version']??0)!==(int)$currentAdmin['auth_version'])) {
 unset($_SESSION['admin'],$_SESSION['auth_version']);$currentAdmin=null;
}

$adminRoles=['admin'=>'ผู้ดูแลระบบหลัก','registry'=>'เจ้าหน้าที่งานบริหารงานทั่วไป','report_viewer'=>'รองผู้อำนวยการฝ่ายบริหารทรัพยากร'];
function userRole(): string {global $currentAdmin;return $currentAdmin['role']??'';}
function canIssue(): bool {return admin() && in_array(userRole(),['admin','registry'],true);}
function accountHome(): string {return userRole()==='registry'?'register':(userRole()==='report_viewer'?'reports':'dashboard');}
