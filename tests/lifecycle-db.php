<?php
declare(strict_types=1);
// CI-only database. Refuse to run against any configured installation.
if (getenv('CRM_TEST_DATABASE') !== 'eft_crm_test') throw new RuntimeException('Test database required');
$configPath=__DIR__.'/../server/beget-api/config.local.php';
if (is_file($configPath)) throw new RuntimeException('Refusing to replace existing configuration');
file_put_contents($configPath,"<?php return ".var_export(['db_host'=>'127.0.0.1','db_name'=>'eft_crm_test','db_user'=>'root','db_password'=>'root','app_secret'=>str_repeat('t',40)],true).';');
try {
 require __DIR__.'/../server/beget-api/bootstrap.php';
 crm_schema_ensure_v30();$db=crm_db();
 $db->exec("INSERT INTO crm_users (username,display_name,password_hash,role) VALUES ('fixture','Fixture','unused','owner')");
 $uid=(int)$db->lastInsertId();$_SESSION=['user_id'=>$uid];$user=['id'=>$uid,'role'=>'owner'];
 $client=crm_uuid();$site=crm_uuid();$event=crm_uuid();$file=crm_uuid();
 $db->prepare("INSERT INTO crm_clients (id,display_name,notes,created_by) VALUES (?,'Restore fixture','Original notes',?)")->execute([$client,$uid]);
 $db->prepare("INSERT INTO crm_sites (id,client_id,name) VALUES (?,?,'Fixture house')")->execute([$site,$client]);
 $db->prepare("INSERT INTO crm_communications (id,site_id,activity_type,channel,direction,external_key,body,attachments_json,occurred_at) VALUES (?,?,'message','max','incoming',?,'Project','[]',NOW())")->execute([$event,$site,$event]);
 $db->prepare("INSERT INTO crm_shared_files (id,site_id,original_name,mime_type,size_bytes,content,created_by) VALUES (?,?,'project.txt','text/plain',7,'project',?)")->execute([$file,$site,$uid]);
 $db->beginTransaction();
 crm_guard_removed_rows($db,['crm_clients'=>[],'crm_sites'=>[],'crm_communications'=>[]],'');
 $db->exec('DELETE FROM crm_communications');$db->exec('DELETE FROM crm_clients');
 $db->exec('UPDATE crm_shared_files SET site_id=NULL');$db->commit();
 $bin=(string)$db->query('SELECT id FROM crm_recycle_bin ORDER BY deleted_at DESC LIMIT 1')->fetchColumn();
 crm_restore_recycled_record($bin,$user);
 if($db->query('SELECT body FROM crm_communications')->fetchColumn()!=='Project' || $db->query('SELECT site_id FROM crm_shared_files')->fetchColumn()!==$site || $db->query('SELECT content FROM crm_shared_files')->fetchColumn()!=='project')throw new RuntimeException('Linked data or file was not restored');
 $db->prepare('UPDATE crm_recycle_bin SET restored_at=NULL WHERE id=?')->execute([$bin]);
 $conflict=false;try{crm_restore_recycled_record($bin,$user);}catch(PDOException $error){$conflict=true;}
 if(!$conflict || $db->inTransaction() || (int)$db->query('SELECT COUNT(*) FROM crm_clients')->fetchColumn()!==1 || $db->query('SELECT restored_at FROM crm_recycle_bin')->fetchColumn()!==null)throw new RuntimeException('Conflict did not roll back atomically');
 $alias=crm_uuid();$db->prepare('INSERT INTO crm_site_aliases VALUES (?,?)')->execute([$alias,$site]);
 if(crm_resolve_site($alias)!==$site)throw new RuntimeException('Merged site resolution failed');
 echo "Recycle bin restoration, file preservation, conflict rollback and alias checks passed\n";
} finally {unlink($configPath);}
