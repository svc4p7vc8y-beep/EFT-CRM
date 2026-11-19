<?php
declare(strict_types=1);
// Deployment diagnostics only: never expose credentials or customer content over HTTP.
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require __DIR__.'/bootstrap.php';require __DIR__.'/avito.php';
restore_exception_handler();crm_schema_ensure_v30();
$report=[];
foreach (['mail','telegram','max','avito'] as $channel) {
    $config=crm_integration_config($channel);
    if (empty($config['enabled'])) { $report[$channel]=['configured'=>false];continue; }
    try {
        if ($channel==='mail') {
            if (!function_exists('imap_open')) throw new RuntimeException('imap_unavailable');
            imap_timeout(IMAP_OPENTIMEOUT,10);
            $imap=@imap_open('{'.$config['imap_host'].':'.(int)($config['imap_port'] ?? 993).'/imap/ssl/validate-cert}INBOX',(string)$config['imap_user'],(string)$config['imap_password'],OP_READONLY,1);
            if (!$imap) throw new RuntimeException('mail_connection_failed');
            imap_close($imap);$report[$channel]=['configured'=>true,'connected'=>true];
        } elseif ($channel==='telegram') {
            $info=crm_http_get_json('https://api.telegram.org/bot'.$config['bot_token'].'/getWebhookInfo');
            $report[$channel]=['configured'=>true,'connected'=>!empty($info['ok']),'webhookRegistered'=>!empty($info['result']['url']),'pendingUpdates'=>(int)($info['result']['pending_update_count'] ?? 0),'webhookHasError'=>!empty($info['result']['last_error_message'])];
        } elseif ($channel==='max') {
            $info=crm_http_get_json('https://platform-api2.max.ru/subscriptions',['Authorization: '.$config['bot_token']]);
            $report[$channel]=['configured'=>true,'connected'=>true,'webhookCount'=>count($info['subscriptions'] ?? [])];
        } else {
            $token=crm_avito_token($config);$account=(string)$config['account_id'];
            $info=crm_avito_request($token,'POST','/messenger/v1/subscriptions',[]);
            $report[$channel]=['configured'=>true,'connected'=>true,'webhookCount'=>count($info['subscriptions'] ?? [])];
            $temporary=tempnam(sys_get_temp_dir(),'eft-photo-probe-');
            try {
                file_put_contents($temporary,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII='));
                crm_avito_upload_image($token,$account,['path'=>$temporary,'mime'=>'image/png','name'=>'eft-upload-check.png']);
                $report[$channel]['imageUpload']=true;
            } catch(Throwable $failure) { $report[$channel]['imageUpload']=false; }
            finally { if(is_file($temporary))unlink($temporary); }
            $chats=crm_avito_request($token,'GET','/messenger/v2/accounts/'.$account.'/chats?limit=100&offset=0');$updated=0;
            foreach ($chats['chats'] ?? [] as $chat) {
                $id=crm_avito_chat_id((string)($chat['id'] ?? ''));
                $q=crm_db()->prepare("SELECT site_id FROM crm_communications WHERE channel='avito' AND site_id IS NOT NULL AND JSON_CONTAINS(attachments_json,JSON_OBJECT('kind','avito_chat','id',?)) LIMIT 1");$q->execute([$id]);$site=$q->fetchColumn();
                if ($site && crm_avito_refresh_contact(crm_resolve_site((string)$site),crm_avito_chat_metadata($chat,$account),$id))$updated++;
            }
            if ($updated)crm_db()->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
            $report[$channel]['contactsRefreshed']=$updated;
        }
    } catch (Throwable $failure) { $report[$channel]=['configured'=>true,'connected'=>false,'checkFailed'=>true]; }
}
$report['whatsapp']=['configured'=>false,'note'=>'No WhatsApp Business API connector configured'];
$report['push']=['activeDevices'=>(int)crm_db()->query('SELECT COUNT(*) FROM crm_push_subscriptions WHERE active=1')->fetchColumn(),
    'pending'=>(int)crm_db()->query('SELECT COUNT(*) FROM crm_notifications WHERE push_sent_at IS NULL AND push_attempts<3')->fetchColumn(),
    'failed'=>(int)crm_db()->query('SELECT COUNT(*) FROM crm_notifications WHERE push_sent_at IS NULL AND push_attempts>=3')->fetchColumn()];
echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
