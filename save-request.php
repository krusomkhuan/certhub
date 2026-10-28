<?php
if(!isset($db)){http_response_code(404);exit;}
if($action==='submit_request') {
 if(time()<($_SESSION['request_after']??0)) throw new RuntimeException('กรุณารอ 30 วินาทีก่อนส่งคำร้องถัดไป');
 $values=[];foreach(['requester','department','contact','title','purpose'] as $key) {$values[$key]=trim($_POST[$key]??'');if($values[$key]==='' || mb_strlen($values[$key])>2000) throw new RuntimeException('กรอกข้อมูลคำร้องให้ครบ แต่ละช่องไม่เกิน 2,000 ตัวอักษร');}
 $date=$_POST['event_date']??'';$type=$_POST['event_type']??'';
 if(!isset($eventTypes[$type]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || date('Y-m-d',strtotime($date))!==$date) throw new RuntimeException('ประเภทงานหรือวันที่ไม่ถูกต้อง');
 $quantity=filter_var($_POST['quantity']??'',FILTER_VALIDATE_INT);
 if(!$quantity || $quantity<1 || $quantity>5000) throw new RuntimeException('ระบุจำนวนเลขที่เป็นจำนวนเต็ม 1–5,000 เลข');
 $token=bin2hex(random_bytes(24));
 run('INSERT INTO certificate_requests(token,requester,department,contact,title,event_type,event_date,purpose,names,submitted_at,quantity) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$token,$values['requester'],$values['department'],$values['contact'],$values['title'],$type,$date,$values['purpose'],'[]',date(DATE_ATOM),$quantity]);
 $_SESSION['request_after']=time()+30;go('?page=request&token='.$token);
}
if(!canIssue()){http_response_code(403);exit;}
if($action==='review_request') {
 $requestId=(int)($_POST['request_id']??0);$decision=$_POST['decision']??'';$note=trim($_POST['review_note']??'');
 if(!in_array($decision,['approved','rejected'],true) || mb_strlen($note)>2000 || ($decision==='rejected' && !$note)) throw new RuntimeException('ระบุผลพิจารณาและเหตุผลเมื่อไม่อนุมัติ ไม่เกิน 2,000 ตัวอักษร');
 $db->exec('BEGIN IMMEDIATE');
 try {
  $request=rows('SELECT * FROM certificate_requests WHERE id=?',[$requestId])[0]??null;
  if(!$request || $request['status']!=='pending') throw new RuntimeException('คำร้องนี้ถูกพิจารณาแล้ว หรือไม่พบคำร้อง');
  $workId=null;
  if($decision==='approved') {
   run('INSERT INTO projects(title,date,end_date,location,description,owner,event_type) VALUES(?,?,?,?,?,?,?)',[$request['title'],$request['event_date'],$request['event_date'],$request['department'],$request['purpose'],$request['requester'],$request['event_type']]);
   $workId=(int)$db->lastInsertId();
   for($item=1;$item<=(int)$request['quantity'];$item++) run('INSERT INTO recipients(project_id,name,organization,role,token,record_only) VALUES(?,?,?,?,?,1)',[$workId,'รายการที่ '.$item.' (ไม่เก็บชื่อผู้รับ)',$request['department'],'general',bin2hex(random_bytes(16))]);
   issueCertificates($workId,false);
  }
  run('UPDATE certificate_requests SET status=?,review_note=?,reviewed_at=?,reviewed_by=?,project_id=? WHERE id=?',[$decision,$note,date(DATE_ATOM),$currentAdmin['id'],$workId,$requestId]);
  $db->exec('COMMIT');
 } catch(Throwable $ex) {$db->exec('ROLLBACK');throw $ex;}
 $_SESSION['notice']=$decision==='approved'?'อนุมัติและออกเลขกลางตามจำนวนที่ขอแล้ว':'บันทึกผลไม่อนุมัติแล้ว';go('?page=requests');
}
