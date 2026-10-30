<?php
declare(strict_types=1);
const CERTHUB_TABLES = ['admins','projects','templates','recipients','photos','settings','certificate_requests'];
function databaseConfig(): array {
 $file=__DIR__.'/config.php';$config=is_file($file)?require $file:[];
 if(!is_array($config)) throw new RuntimeException('Invalid database configuration');
 foreach(['host'=>'DB_HOST','port'=>'DB_PORT','database'=>'DB_DATABASE','username'=>'DB_USERNAME','password'=>'DB_PASSWORD','ssl_ca'=>'DB_SSL_CA'] as $key=>$env) {
  $value=getenv('CERTHUB_'.$env);if($value!==false) $config[$key]=$value;
 }
 if(empty($config['database']) || empty($config['username'])) throw new RuntimeException('Configure MySQL in config.php');
 return array_merge(['host'=>'127.0.0.1','port'=>3306,'password'=>'','ssl_ca'=>''],$config);
}
function connectDatabase(?array $config=null): PDO {
 $config??=databaseConfig();
 foreach(['host','database'] as $key) if(preg_match('/[;\x00-\x1F]/',(string)$config[$key])) throw new RuntimeException('Invalid database configuration');
 $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
 if(!empty($config['ssl_ca'])) {$options[PDO::MYSQL_ATTR_SSL_CA]=$config['ssl_ca'];$options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true;}
 $db=new PDO('mysql:host='.$config['host'].';port='.(int)$config['port'].';dbname='.$config['database'].';charset=utf8mb4',$config['username'],$config['password'],$options);
 $db->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
 return $db;
}
function initializeDatabase(PDO $db): void {
 $sql=ltrim(file_get_contents(__DIR__.'/database/schema.sql'),"\xEF\xBB\xBF");
 foreach(explode(';',$sql) as $statement) if(trim($statement)!=='') $db->exec($statement);
}
function migrateDatabase(PDO $db): void {
 // mysql-2: the central sequence restarts every Buddhist year, so uniqueness is per (year, sequence).
 $db->query("SELECT GET_LOCK('certhub_migrate',30)");
 try {
  if($db->query("SELECT value FROM settings WHERE name='schema_version'")->fetchColumn()!=='mysql-1') return;
  if(!in_array('sequence_year',array_column($db->query('SHOW COLUMNS FROM recipients')->fetchAll(),'Field'),true)) $db->exec('ALTER TABLE recipients ADD COLUMN sequence_year INT NULL AFTER central_sequence');
  $db->exec("UPDATE recipients SET sequence_year=IF(issued_at REGEXP '^[0-9]{4}-',CAST(LEFT(issued_at,4) AS UNSIGNED)+543,0) WHERE central_sequence IS NOT NULL AND sequence_year IS NULL");
  $indexes=array_column($db->query('SHOW INDEX FROM recipients')->fetchAll(),'Key_name');
  if(!in_array('year_sequence_unique',$indexes,true)) $db->exec('ALTER TABLE recipients ADD UNIQUE KEY year_sequence_unique(sequence_year,central_sequence)');
  if(in_array('central_sequence_unique',$indexes,true)) $db->exec('ALTER TABLE recipients DROP INDEX central_sequence_unique');
  $year=(int)$db->query('SELECT MAX(sequence_year) FROM recipients')->fetchColumn()?:(int)date('Y')+543;
  $db->prepare("INSERT IGNORE INTO settings(name,value) VALUES('central_year',?)")->execute([(string)$year]);
  $db->exec("UPDATE settings SET value='mysql-2' WHERE name='schema_version'");
 } finally {$db->query("SELECT RELEASE_LOCK('certhub_migrate')");}
}
function beginNumberingTransaction(): void {
 global $db;
 $db->beginTransaction();
 // All issuers and numbering settings lock this same row first.
 // The transaction and UNIQUE indexes protect both sequence and printed number.
 try {
  $lock=$db->query("SELECT value FROM settings WHERE name='central_next' FOR UPDATE")->fetchColumn();
  if($lock===false) throw new RuntimeException('ไม่พบตัวนับเลขกลาง กรุณาตรวจการติดตั้งฐานข้อมูล');
 } catch(Throwable $ex) {$db->rollBack();throw $ex;}
}
function exportMysqlSnapshot(PDO $db,string $path): array {
 $out=fopen($path,'wb');if(!$out) throw new RuntimeException('ไม่สามารถสร้างไฟล์สำรอง SQL');
 $write=static function(string $text) use($out): void {if(fwrite($out,$text)!==strlen($text)) throw new RuntimeException('พื้นที่ไม่พอสำหรับไฟล์สำรอง');};
 try {
  $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
  $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
  $write("-- CertHub MySQL backup. Import into an EMPTY database.\nSET NAMES utf8mb4;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
  foreach(CERTHUB_TABLES as $table) {
   $create=$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
   $write($create.";\n");
   $statement=$db->query('SELECT * FROM `'.$table.'`');
   while($row=$statement->fetch(PDO::FETCH_ASSOC)) {
    $columns=implode(',',array_map(fn($key)=>'`'.$key.'`',array_keys($row)));
    // Hex literals preserve UTF-8, quotes, newlines and backslashes regardless of SQL mode.
    $values=implode(',',array_map(fn($value)=>$value===null?'NULL':"CONVERT(X'".bin2hex((string)$value)."' USING utf8mb4)",array_values($row)));
    $write('INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.$values.");\n");
   }
  }
  $media=$db->query('SELECT filename FROM photos UNION SELECT background FROM templates WHERE background IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
  $db->commit();return $media;
 } catch(Throwable $ex) {if($db->inTransaction()) $db->rollBack();throw $ex;}
 finally {fclose($out);}
}
