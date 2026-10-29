<?php
if(!isset($db) || !admin()) {http_response_code(403);exit;}
set_time_limit(300);
$stamp=date('Ymd-His');
$temp=__DIR__.'/data/backup-'.bin2hex(random_bytes(16));
$snapshot=$temp.'.sqlite';$archive=$temp.'.zip';
try {
 // VACUUM INTO creates a consistent, independent SQLite snapshot.
 $db->exec('VACUUM INTO '.$db->quote($snapshot));
 $copy=new PDO('sqlite:'.$snapshot);
 $media=$copy->query('SELECT filename FROM photos UNION SELECT background FROM templates WHERE background IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
 $copy=null;
 $zip=new PharData($archive,0,null,Phar::ZIP);
 $entries=[];
 $add=function(string $path,string $name) use($zip,&$entries): void {
  if(!is_file($path)) throw new RuntimeException('สำรองไม่สำเร็จ: ไม่พบไฟล์ '.$name);
  $zip->addFile($path,$name);$entries[$name]=hash_file('sha256',$path);
 };
 $add($snapshot,'data/certhub.sqlite');$add(__DIR__.'/data/.htaccess','data/.htaccess');
 foreach($media as $file) {
  if(!preg_match('/^[a-f0-9]{40}\.(jpg|png|webp)$/',$file)) throw new RuntimeException('พบชื่อไฟล์ภาพไม่ถูกต้อง');
  $add(__DIR__.'/data/'.$file,'data/'.$file);
 }
 foreach(['index.php','public-project.php','bootstrap.php','template.php','settings.php','save-settings.php','privacy.php','profile.php','save-profile.php','admins.php','save-admin.php','register.php','save-register.php','guide.php','request.php','requests.php','save-request.php','pending-issues.php','reports.php','backup.php','.htaccess','README.md','version.php','CHANGELOG.md'] as $file) $add(__DIR__.'/'.$file,$file);
 foreach(glob(__DIR__.'/assets/*') as $file) if(is_file($file)) $add($file,'assets/'.basename($file));
 $zip->addFromString('manifest.json',json_encode(['application'=>'CertHub','app_version'=>CERTHUB_VERSION,'format_version'=>1,'created_at'=>date(DATE_ATOM),'timezone'=>'Asia/Bangkok','sha256'=>$entries],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 $zip->addFromString('RESTORE.txt',"CertHub full backup\r\n1. Stop Apache and preserve the current application folder separately.\r\n2. Extract into a separate folder, verify manifest SHA-256 and data/.htaccess.\r\n3. Use PHP with PDO SQLite, mbstring, fileinfo and Phar.\r\n4. Point Apache at the restored folder, restrict access until verification finishes.\r\n5. Reconcile all certificate numbers issued after the backup; advance central numbering beyond every issued number before issuing again. Verify admin login, project counts, images and certificates. Review public URL, privacy requests and retention before reopening.\r\nThe archive contains personal data and password hashes. Store it securely.\r\nCertificates are rendered from database records and templates; individual PDFs are not stored.\r\n");
 unset($zip);
 header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="CertHub-backup-'.$stamp.'.zip"');
 header('Content-Length: '.filesize($archive));header('Cache-Control: private, no-store');
 readfile($archive);
} finally {
 unset($zip,$copy);
 foreach([$snapshot,$archive] as $file) if(is_file($file)) unlink($file);
}
exit;
