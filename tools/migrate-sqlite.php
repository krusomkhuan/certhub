<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/database.php';
try {
 $source=$argv[1]??dirname(__DIR__).'/data/certhub.sqlite';
 if(!is_file($source)) throw new RuntimeException('SQLite source not found');
 $sqlite=new PDO('sqlite:'.$source,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db=connectDatabase();initializeDatabase($db);
 // Migration is intentionally refused when destination already has application data.
 foreach(CERTHUB_TABLES as $table) {
  $where=$table==='settings'?" WHERE name NOT IN ('schema_version','central_pattern','central_next','central_width')":'';
  if((int)$db->query('SELECT COUNT(*) FROM `'.$table.'`'.$where)->fetchColumn()>0) throw new RuntimeException('Destination is not empty: '.$table);
 }
 $sqlite->beginTransaction();$db->beginTransaction();$counts=[];
 foreach(CERTHUB_TABLES as $table) {
  $targetColumns=array_column($db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(),'Field');
  if($table==='settings') $db->exec('DELETE FROM settings');
  $read=$sqlite->query('SELECT * FROM "'.$table.'"');$count=0;
  while($row=$read->fetch(PDO::FETCH_ASSOC)) {
   $row=array_intersect_key($row,array_flip($targetColumns));
   $columns=implode(',',array_map(fn($key)=>'`'.$key.'`',array_keys($row)));
   $insert=$db->prepare('INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
   $insert->execute(array_values($row));$count++;
  }
  $counts[$table]=$count;
  if((int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==$count) throw new RuntimeException('Count mismatch: '.$table);
 }
 // Compare every migrated field, including hashes, tokens and certificate numbers, before commit.
 foreach(CERTHUB_TABLES as $table) {
  $key=$table==='settings'?'name':'id';
  foreach($sqlite->query('SELECT * FROM "'.$table.'"') as $row) {
   $row=array_filter($row,fn($key)=>is_string($key),ARRAY_FILTER_USE_KEY);
   $q=$db->prepare('SELECT * FROM `'.$table.'` WHERE `'.$key.'`=?');$q->execute([$row[$key]]);$target=$q->fetch();
   foreach($row as $field=>$value) if(array_key_exists($field,$target) && ($value===null?$target[$field]!==null:(string)$value!==(string)$target[$field])) throw new RuntimeException('Verification mismatch: '.$table.'.'.$field);
  }
 }
 $db->exec("INSERT INTO settings(name,value) VALUES('schema_version','mysql-1') ON DUPLICATE KEY UPDATE value=VALUES(value)");
 $db->commit();$sqlite->commit();
 // Legacy rows have no sequence_year; the mysql-2 upgrade backfills it from issued_at.
 migrateDatabase($db);
 echo json_encode(['migrated'=>true,'verified_counts'=>$counts,'source_preserved'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch(Throwable $ex) {
 if(isset($db) && $db->inTransaction()) $db->rollBack();
 if(isset($sqlite) && $sqlite->inTransaction()) $sqlite->rollBack();
 fwrite(STDERR,'Migration failed: '.$ex->getMessage().PHP_EOL);exit(1);
}
