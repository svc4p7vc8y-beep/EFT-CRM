<?php
declare(strict_types=1);
if (getenv('CRM_TEST_DATABASE')!=='eft_crm_test') throw new RuntimeException('Isolated database required');
$configPath=__DIR__.'/../server/beget-api/config.local.php';
if (is_file($configPath)) { echo "::error::Communication test configuration unexpectedly exists\n";throw new RuntimeException('Refusing existing configuration'); }
file_put_contents($configPath,'<?php return '.var_export(['db_host'=>'127.0.0.1','db_name'=>'eft_crm_test','db_user'=>'root','db_password'=>'root','app_secret'=>str_repeat('t',40)],true).';');
try {
    require __DIR__.'/../server/beget-api/bootstrap.php';require __DIR__.'/../server/beget-api/avito.php';
    restore_exception_handler();crm_schema_ensure_v30();$db=crm_db();
    $users=[];$employees=[];
    foreach (['owner','manager','manager'] as $i=>$role) {
        $employee=crm_uuid();$employees[]=$employee;
        $db->prepare("INSERT INTO crm_employees (id,full_name,notes) VALUES (?,?,'')")->execute([$employee,'Notification '.$i]);
        $db->prepare("INSERT INTO crm_users (username,display_name,password_hash,role,employee_id) VALUES (?,?,'unused',?,?)")->execute(['notify-'.$i,'Notify '.$i,$role,$employee]);$users[]=(int)$db->lastInsertId();
    }
    $db->prepare('INSERT INTO crm_notification_preferences (user_id,all_events_enabled) VALUES (?,1)')->execute([$users[1]]);
    $client=crm_uuid();$site=crm_uuid();$lead=crm_uuid();
    $db->prepare("INSERT INTO crm_clients (id,display_name,notes) VALUES (?,'Photo client','')")->execute([$client]);
    $db->prepare("INSERT INTO crm_sites (id,client_id,name) VALUES (?,?,'House')")->execute([$site,$client]);
    $db->prepare("INSERT INTO crm_leads (id,site_id,public_number,owner_employee_id,notes) VALUES (?,?,'NOTIFY-1',?,'')")->execute([$lead,$site,$employees[0]]);
    foreach (['avito','max','email','telegram','whatsapp'] as $channel) {
        $id=crm_uuid();
        $db->prepare("INSERT INTO crm_communications (id,site_id,activity_type,channel,direction,body,attachments_json,occurred_at) VALUES (?,?,'message',?,'incoming','Attachment message','[]',NOW())")->execute([$id,$site,$channel]);
        crm_push_notify_site($site,$id);crm_push_notify_site($site,$id);
        $q=$db->prepare('SELECT user_id,target_path FROM crm_notifications WHERE event_key=?');$q->execute(['lead-message:'.$id]);$rows=$q->fetchAll();
        $ids=array_map('intval',array_column($rows,'user_id'));
        if (!in_array($users[0],$ids,true) || !in_array($users[1],$ids,true) || in_array($users[2],$ids,true) || count($ids)!==2) throw new RuntimeException('Notification recipients or deduplication failed: '.$channel);
        if (!str_contains($rows[0]['target_path'],'#client/'.$lead.'?event='.$id)) throw new RuntimeException('Missing message deep link');
        $db->prepare('UPDATE crm_communications SET is_ignored=1 WHERE id=?')->execute([$id]);
        $db->prepare('DELETE FROM crm_notifications WHERE event_key=?')->execute(['lead-message:'.$id]);crm_push_notify_site($site,$id);
        $q->execute(['lead-message:'.$id]);if($q->fetch())throw new RuntimeException('Ignored message notified');
    }
    $id=crm_uuid();$db->prepare("INSERT INTO crm_communications (id,activity_type,channel,direction,body,attachments_json,occurred_at) VALUES (?,'message','email','incoming','No client','[]',NOW())")->execute([$id]);crm_push_notify_site('',$id);
    $q=$db->prepare('SELECT user_id FROM crm_notifications WHERE event_key=?');$q->execute(['message:'.$id]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    if(!in_array($users[0],$ids,true) || in_array($users[2],$ids,true))throw new RuntimeException('Unassigned inbox access failed');
    crm_avito_refresh_contact($site,['avatar'=>'https://www.avito.st/photo.png'],'chat');
    $db->prepare("UPDATE crm_clients SET notes='Edited' WHERE id=?")->execute([$client]);
    $q=$db->prepare('SELECT avito_avatar_url FROM crm_clients WHERE id=?');$q->execute([$client]);if($q->fetchColumn()!=='https://www.avito.st/photo.png')throw new RuntimeException('Avatar missing');
    $public=crm_public_workspace();$found=array_values(array_filter($public['clients'],static fn($c)=>$c['id']===$client));if(($found[0]['avitoAvatar'] ?? '')!=='https://www.avito.st/photo.png')throw new RuntimeException('Avatar not exposed to UI');
    echo "All channel notifications: recipients, preferences, deduplication, deep links, ignored messages, unassigned email and Avito avatar persistence passed\n";
} catch (Throwable $failure) {
    echo '::error::Communications fixture: '.str_replace(["\r","\n"], ' ', $failure->getMessage())." at line ".$failure->getLine()."\n";
    throw $failure;
} finally { unlink($configPath); }
