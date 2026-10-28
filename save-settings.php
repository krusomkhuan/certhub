<?php
if(!isset($db) || !admin()) {http_response_code(403);exit;}
$url=rtrim(trim($_POST['public_url']??''),'/');
if($url && (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true) || parse_url($url,PHP_URL_USER) || parse_url($url,PHP_URL_PASS) || parse_url($url,PHP_URL_QUERY) || parse_url($url,PHP_URL_FRAGMENT) || strlen($url)>300 || preg_match('/[^\x21-\x7E]/',$url))) throw new RuntimeException('URL ต้องเป็น http:// หรือ https:// ไม่มีชื่อผู้ใช้ query หรือ fragment และยาวไม่เกิน 300 ตัวอักษร');
$contact=trim($_POST['privacy_contact']??'');$basis=trim($_POST['privacy_basis']??'');
if(!empty($_POST['privacy_published']) && (!$contact || !$basis)) throw new RuntimeException('กรอกช่องทางติดต่อและฐานการประมวลผลก่อนประกาศใช้นโยบาย');
foreach(['public_url'=>$url,'privacy_contact'=>$contact,'privacy_basis'=>$basis,'privacy_published'=>empty($_POST['privacy_published'])?'0':'1','privacy_updated'=>date('Y-m-d')] as $key=>$value) saveSetting($key,$value);
$_SESSION['notice']='บันทึกการตั้งค่าแล้ว';go('?page=settings');
