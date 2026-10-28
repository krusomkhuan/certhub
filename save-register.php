<?php
if(!isset($db) || !canIssue()){http_response_code(403);exit;}
if($action==='central_numbering') {
 $pattern=trim($_POST['number_pattern']??'');$next=filter_var($_POST['number_next']??'',FILTER_VALIDATE_INT);$width=filter_var($_POST['number_width']??'',FILTER_VALIDATE_INT);
 if(substr_count($pattern,'{seq}')!==1 || mb_strlen($pattern)>100 || preg_match('/[{}]/',str_replace(['{year}','{seq}'],'',$pattern)) || preg_match('/[\x00-\x1F\x7F]/',$pattern) || !$next || $next<1 || $next>999999999 || !$width || $width<1 || $width>9) throw new RuntimeException('รูปแบบต้องมี {seq} หนึ่งครั้ง ใช้ {year} ได้ ลำดับ 1–999999999 และจำนวนหลัก 1–9');
 $db->exec('BEGIN IMMEDIATE');
 try {
  $minimum=max((int)setting('central_next'),(int)rows('SELECT COALESCE(MAX(central_sequence),0)+1 n FROM recipients')[0]['n']);
  if($next<$minimum) throw new RuntimeException('ห้ามย้อนลำดับเลขกลาง ต้องเริ่มที่ '.$minimum.' ขึ้นไป');
  saveSetting('central_pattern',$pattern);saveSetting('central_next',(string)$next);saveSetting('central_width',(string)$width);
  $db->exec('COMMIT');
 } catch(Throwable $ex) {$db->exec('ROLLBACK');throw $ex;}
 $_SESSION['notice']='บันทึกรูปแบบเลขกลางทั้งวิทยาลัยแล้ว เลขที่ออกแล้วไม่เปลี่ยน';go('?page=register');
}
if($action==='direct_issue') {
 if(!hash_equals($_SESSION['issue_nonce']??'',(string)($_POST['issue_nonce']??'')) || empty($_SESSION['issue_nonce'])) throw new RuntimeException('แบบฟอร์มนี้ถูกบันทึกแล้วหรือหมดอายุ กรุณาเปิดแบบฟอร์มใหม่');
 $names=array_values(array_filter(array_map('trim',preg_split('/\R/u',$_POST['names']??($_POST['name']??''))),fn($name)=>$name!==''));
 if(!$names || count($names)>5000) throw new RuntimeException('กรอกรายชื่อ 1–5,000 คน หนึ่งคนต่อบรรทัด');
 if(count($names)!==count(array_unique($names))) throw new RuntimeException('พบชื่อซ้ำในชุด กรุณาตรวจสอบก่อนออกเลข');
 foreach($names as $name) if(mb_strlen($name)>200) throw new RuntimeException('ชื่อผู้รับต้องไม่เกิน 200 ตัวอักษร');
 if(!in_array($_POST['output_mode']??'register',['register','certificate'],true)) throw new RuntimeException('รูปแบบการออกเลขไม่ถูกต้อง');
 $recordOnly=($_POST['output_mode']??'register')==='register';
 $title=trim($_POST['title']??'');$owner=trim($_POST['owner']??'');$heading=trim($_POST['heading']??'');$date=$_POST['date']??'';$type=$_POST['event_type']??'other';
 if(!$title || !$owner || (!$recordOnly && !$heading) || !isset($eventTypes[$type])) throw new RuntimeException('กรุณาระบุชื่องาน ผู้รับ ผู้รับผิดชอบ ข้อความรับรอง และประเภทงาน');
 if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || date('Y-m-d',strtotime($date))!==$date) throw new RuntimeException('วันที่ไม่ถูกต้อง');
 $db->exec('BEGIN IMMEDIATE');
 try {
  run('INSERT INTO projects(title,date,end_date,location,description,owner,event_type,published) VALUES(?,?,?,?,?,?,?,?)',[$title,$date,$date,trim($_POST['location']??''),'ออกเกียรติบัตรจากทะเบียนกลาง',$owner,$type,!$recordOnly && !empty($_POST['published'])?1:0]);
  $workId=(int)$db->lastInsertId();
  if(!$recordOnly) foreach($roles as $role=>$label) run('INSERT INTO templates(project_id,role,heading,signer,position) VALUES(?,?,?,?,?)',[$workId,$role,$role==='general'?$heading:'เพื่อแสดงว่าได้เป็น'.$label.'ในงานนี้',trim($_POST['signer']??''),trim($_POST['position']??'')]);
  foreach($names as $name) { $token=bin2hex(random_bytes(16));
  run('INSERT INTO recipients(project_id,name,organization,role,token,record_only) VALUES(?,?,?,?,?,?)',[$workId,$name,trim($_POST['organization']??''),'general',$token,$recordOnly?1:0]); }
  issueCertificates($workId,false);
  $numbers=array_column(rows('SELECT certificate_number FROM recipients WHERE project_id=? ORDER BY id',[$workId]),'certificate_number');
  $number=count($numbers)===1?$numbers[0]:$numbers[0].' ถึง '.$numbers[count($numbers)-1];
  $db->exec('COMMIT');
 } catch(Throwable $ex) {$db->exec('ROLLBACK');throw $ex;}
 unset($_SESSION['issue_nonce']);
 $_SESSION['notice']='ออกเลขที่ '.$number.' จำนวน '.count($numbers).' เลข'.($recordOnly?' แล้ว (ลงทะเบียนเลขที่อย่างเดียว ไม่มีเอกสาร)':' แล้ว ดู/พิมพ์เกียรติบัตรได้จากทะเบียนด้านล่าง');go('?page=register');
}
