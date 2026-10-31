<?php
declare(strict_types=1);

function crm_avito_token(array $config): string {
    $clientId = (string)($config['client_id'] ?? '');
    $clientSecret = (string)($config['client_secret'] ?? '');
    if ($clientId === '' || $clientSecret === '') throw new RuntimeException('Укажите Client ID и Client Secret Авито.');
    $curl = curl_init('https://api.avito.ru/token');
    if ($curl === false) throw new RuntimeException('Не удалось подготовить запрос к Авито.');
    curl_setopt_array($curl, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'client_credentials','client_id'=>$clientId,'client_secret'=>$clientSecret])]);
    $raw = curl_exec($curl); $status = (int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
    $data = is_string($raw) ? json_decode($raw,true) : null;
    if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['access_token'])) throw new RuntimeException('Авито отклонил ключи API. Проверьте доступ к Messenger API и данные приложения.');
    return (string)$data['access_token'];
}

function crm_avito_request(string $token, string $method, string $path, ?array $body = null): array {
    if (!preg_match('~^/messenger/[a-zA-Z0-9/_?=&%-]+$~',$path)) throw new InvalidArgumentException('Недопустимый адрес API Авито.');
    $curl = curl_init('https://api.avito.ru'.$path);
    if ($curl === false) throw new RuntimeException('Не удалось подготовить запрос к Авито.');
    $options = [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json']];
    if ($method === 'POST') { $options[CURLOPT_POST]=true; $options[CURLOPT_HTTPHEADER][]='Content-Type: application/json'; $options[CURLOPT_POSTFIELDS]=json_encode($body ?? [],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    curl_setopt_array($curl,$options);
    $raw = curl_exec($curl); $status = (int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
    $data = is_string($raw) ? json_decode($raw,true) : null;
    if ($status < 200 || $status >= 300) throw new RuntimeException('Авито вернул ошибку '.($status ?: 'соединения').'. Проверьте доступ к мессенджеру и повторите попытку.');
    return is_array($data) ? $data : [];
}

function crm_avito_chat_id(string $value): string {
    if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/',$value)) throw new InvalidArgumentException('Некорректный идентификатор диалога Авито.');
    return $value;
}

function crm_avito_chat_metadata(array $chat, string $accountId): array {
    $name = 'Собеседник Авито';
    foreach ((array)($chat['users'] ?? []) as $participant) if (is_array($participant) && (string)($participant['id'] ?? '') !== $accountId && trim((string)($participant['name'] ?? '')) !== '') { $name=crm_text($participant['name'],100); break; }
    $ad = is_array($chat['context']['value'] ?? null) ? $chat['context']['value'] : [];
    $url = (string)($ad['url'] ?? '');
    if (!preg_match('~^https://(?:www\.)?avito\.ru/~i',$url)) $url = '';
    return ['sender'=>$name,'adTitle'=>crm_text($ad['title'] ?? '',200),'adUrl'=>$url,'itemId'=>crm_text($ad['id'] ?? '',30)];
}

function crm_avito_media(array $content): array {
    $sources = [];
    foreach (['file', 'document', 'video', 'audio'] as $kind) {
        $file = $content[$kind] ?? null;
        if (is_array($file) && is_string($file['url'] ?? $file['link'] ?? null)) $sources[] = ['url'=>$file['url'] ?? $file['link'], 'name'=>$file['name'] ?? $file['filename'] ?? $file['file_name'] ?? 'Файл из Авито'];
    }
    if (is_string($content['image']['url'] ?? null)) $sources[]=['url'=>$content['image']['url'],'name'=>'Фото из Авито'];
    $sizes = $content['image']['sizes'] ?? [];
    if (is_array($sizes) && $sizes) {
        uksort($sizes, static fn($a,$b) => (int)explode('x',(string)$b)[0] <=> (int)explode('x',(string)$a)[0]);
        foreach ($sizes as $url) if (is_string($url)) { $sources[]=['url'=>$url,'name'=>'Фото из Авито']; break; }
    }
    $result = [];
    foreach (array_slice($sources,0,8) as $source) {
        $parts = parse_url($source['url']); $host = strtolower((string)($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || !preg_match('/(^|\.)(avito\.ru|avito\.st|avito\.net)$/', $host) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) continue;
        $directory = dirname(__DIR__).'/uploads/communications';
        if (!is_dir($directory) && !mkdir($directory,0770,true) && !is_dir($directory)) continue;
        $temporary = tempnam($directory,'avito-'); if ($temporary === false) continue;
        $stream = fopen($temporary,'wb'); if ($stream === false) { unlink($temporary); continue; }
        $size=0; $curl=curl_init($source['url']);
        curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use($stream,&$size):int { if ($size+strlen($chunk)>25*1024*1024) return 0; $written=fwrite($stream,$chunk); $size+=(int)$written; return (int)$written; }]);
        curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl); fclose($stream);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($temporary);
        $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf','text/plain'=>'txt','application/zip'=>'zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx'];
        if ($status!==200 || !$size || !isset($extensions[$mime])) { unlink($temporary); continue; }
        $key='m-'.bin2hex(random_bytes(16)).'.'.$extensions[$mime];
        if (!rename($temporary,$directory.'/'.$key)) { unlink($temporary); continue; }
        $result[]=['kind'=>'avito_media','key'=>$key,'name'=>crm_text($source['name'],220),'mime'=>$mime,'size'=>$size,'url'=>'/api/api.php?action=communications.file&key='.rawurlencode($key)];
    }
    return $result;
}

function crm_avito_import_message(array $config, string $chatId, array $message, array $metadata = []): bool {
    $id = (string)($message['id'] ?? '');
    if ($id === '' || strlen($id) > 200 || !preg_match('/^[A-Za-z0-9_-]+$/',$id)) return false;
    $existingQuery=crm_db()->prepare('SELECT id,attachments_json FROM crm_communications WHERE external_key=? LIMIT 1');
    $existingQuery->execute(['avito-'.$id]); $existing=$existingQuery->fetch();
    $oldEntries=$existing ? (json_decode((string)$existing['attachments_json'],true) ?: []) : [];
    $mappedSite=(string)($config['chat_site_ids'][$chatId] ?? '');
    if ($mappedSite === '') $mappedSite=crm_external_conversation_site('avito',$chatId,(string)($metadata['sender'] ?? 'Клиент Авито'),(string)($metadata['adTitle'] ?? ''));
    if ($existing && array_filter($oldEntries,static fn($entry)=>($entry['kind'] ?? '')==='avito_media')) return false;
    $direction = ($message['direction'] ?? 'in') === 'out' ? 'outgoing' : 'incoming';
    $content = is_array($message['content'] ?? null) ? $message['content'] : [];
    $kind = (string)($message['type'] ?? 'text');
    $body = trim((string)($content['text'] ?? $content['link']['text'] ?? $content['item']['title'] ?? ''));
    if ($body === '') $body = match ($kind) { 'file'=>'Документ из Авито','document'=>'Документ из Авито','image'=>'Фото в Авито','voice'=>'Голосовое сообщение в Авито','call'=>'Звонок через Авито','deleted'=>'Сообщение удалено','system'=>'Системное сообщение Авито',default=>'Сообщение в Авито' };
    $body = crm_text($body,20000);
    $media=crm_avito_media($content);
    if ($existing) {
        if (!$media) {
            if (!in_array($kind,['file','document','image','voice','video'],true) || array_filter($oldEntries,static fn($entry)=>($entry['kind'] ?? '')==='avito_media_unavailable' || !empty($entry['key']))) return false;
            $media=[['kind'=>'avito_media_unavailable','name'=>in_array($kind,['file','document'],true)?'Документ из Авито':'Вложение из Авито','chatId'=>$chatId]];
        }
        $update=crm_db()->prepare('UPDATE crm_communications SET attachments_json=?,body=? WHERE id=?');
        $update->execute([json_encode([...$oldEntries,...$media],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$body,$existing['id']]);
        return true;
    }
    $sender = (string)($metadata['sender'] ?? 'Собеседник Авито');
    $attachments = [['kind'=>'avito_chat','id'=>$chatId],['kind'=>'sender','name'=>$sender],['kind'=>'workflow','status'=>$direction==='incoming'?'reply':'waiting','followUpAt'=>'']];
    if (!empty($metadata['adTitle']) || !empty($metadata['adUrl'])) $attachments[] = ['kind'=>'avito_ad','title'=>(string)($metadata['adTitle'] ?? ''),'url'=>(string)($metadata['adUrl'] ?? ''),'itemId'=>(string)($metadata['itemId'] ?? '')];
    $attachments = [...$attachments,...$media];
    if (!$media && in_array($kind,['image','file','document','video','voice'],true)) $attachments[]=['kind'=>'avito_media_unavailable','name'=>in_array($kind,['file','document'],true)?'Документ из Авито':'Вложение из Авито','chatId'=>$chatId];
    $siteId = $mappedSite;
    $site = null;
    if ($siteId !== '') { $check=crm_db()->prepare('SELECT id FROM crm_sites WHERE id=?'); $check->execute([$siteId]); $site=$check->fetchColumn() ?: null; }
    $created = (int)($message['created'] ?? time());
    if ($created < 946684800 || $created > time()+86400) $created=time();
    $externalKey = 'avito-'.$id;
    $statement = crm_db()->prepare("INSERT IGNORE INTO crm_communications (id,site_id,task_id,activity_type,channel,direction,external_key,subject,body,is_read,attachments_json,delivery_status,delivery_error,occurred_at,author_id,author_employee_id) VALUES (?,?,NULL,'message','avito',?,?,?, ?,?,?,?,'',?,NULL,NULL)");
    $eventId = crm_uuid();
    $statement->execute([$eventId,$site,$direction,$externalKey,crm_text($metadata['adTitle'] ?? '',200),$body,$direction==='outgoing'?1:0,json_encode($attachments,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'delivered',date('Y-m-d H:i:s',$created)]);
    if ($statement->rowCount() && $direction === 'incoming') {
        try { if ($site) crm_push_notify_site((string)$site,$eventId); } catch (Throwable $error) { error_log('EFT CRM Avito push failed: '.$error->getMessage()); }
    }
    return $statement->rowCount() > 0;
}

function crm_avito_sync(array $config, int $chatLimit = 30): array {
    $accountId = (string)($config['account_id'] ?? '');
    if (!preg_match('/^\d{1,20}$/',$accountId)) throw new RuntimeException('Укажите ID профиля Авито.');
    $token=crm_avito_token($config);
    $list=crm_avito_request($token,'GET','/messenger/v2/accounts/'.$accountId.'/chats?limit='.$chatLimit.'&offset=0');
    $chats=is_array($list['chats'] ?? null) ? $list['chats'] : [];
    $imported=0; $processed=0; $metadataMap=is_array($config['chat_metadata'] ?? null)?$config['chat_metadata']:[];
    foreach ($chats as $chat) {
        if (!is_array($chat)) continue;
        try { $chatId=crm_avito_chat_id((string)($chat['id'] ?? '')); } catch (Throwable) { continue; }
        $meta=crm_avito_chat_metadata($chat,$accountId); $metadataMap[$chatId]=$meta;
        $messages=crm_avito_request($token,'GET','/messenger/v3/accounts/'.$accountId.'/chats/'.rawurlencode($chatId).'/messages/?limit=100&offset=0');
        if (!array_is_list($messages)) $messages=$messages['messages'] ?? [];
        if (!is_array($messages)) $messages=[];
        foreach (array_reverse($messages) as $message) if (is_array($message) && crm_avito_import_message($config,$chatId,$message,$meta)) $imported++;
        $processed++;
    }
    $config['chat_metadata']=array_slice($metadataMap,-500,null,true);
    crm_store_private_integration('avito',$config);
    if ($imported) crm_db()->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
    return ['chats'=>$processed,'imported'=>$imported];
}

function crm_avito_send(array $config, string $chatId, string $text): array {
    $accountId=(string)($config['account_id'] ?? '');
    if (!preg_match('/^\d{1,20}$/',$accountId)) throw new RuntimeException('Не указан профиль Авито.');
    $chatId=crm_avito_chat_id($chatId);
    if (mb_strlen($text)>1000) throw new RuntimeException('Ответ в Авито должен быть не длиннее 1000 символов.');
    $token=crm_avito_token($config);
    return crm_avito_request($token,'POST','/messenger/v1/accounts/'.$accountId.'/chats/'.rawurlencode($chatId).'/messages',['type'=>'text','message'=>['text'=>$text]]);
}

function crm_avito_greeting_settings(): array {
    $q=crm_db()->prepare('SELECT setting_value FROM crm_settings WHERE setting_key=?');$q->execute(['avito_greeting_settings']);$stored=json_decode((string)$q->fetchColumn(),true);
    return is_array($stored)?$stored:['enabled'=>false,'text'=>'Здравствуйте! Спасибо за обращение в ЭФТ. Чтобы подготовить расчёт, заполните короткую анкету: https://eftsip.ru/EFT_client_questionnaire.html . Если удобнее, опишите задачу прямо здесь — мы поможем.'];
}
function crm_avito_greet_new_chat(array $config,string $chatId,array $message,array $meta): void {
    $settings=crm_avito_greeting_settings();
    if(empty($settings['enabled']) || ($message['type'] ?? '')==='system' || ($message['direction'] ?? 'in')==='out' || abs(time()-(int)($message['created'] ?? 0))>300) return;
    $db=crm_db();$q=$db->prepare("SELECT id,site_id,direction FROM crm_communications WHERE channel='avito' AND body NOT LIKE '[Системное сообщение]%' AND JSON_CONTAINS(attachments_json,JSON_OBJECT('kind','avito_chat','id',?)) ORDER BY occurred_at LIMIT 2");$q->execute([$chatId]);$rows=$q->fetchAll();
    if(count($rows)!==1 || $rows[0]['direction']!=='incoming') return;
    $claim='avito-greeting:'.substr(hash('sha256',$chatId),0,40);
    $q=$db->prepare('INSERT IGNORE INTO crm_settings (setting_key,setting_value) VALUES (?,?)');$q->execute([$claim,'sending']);if(!$q->rowCount())return;
    $text=(string)$settings['text'];$status='sent';$error='';$external=$claim;
    try { $sent=crm_avito_send($config,$chatId,$text);$external=!empty($sent['id'])?'avito-'.$sent['id']:$claim; }
    catch(Throwable $failure) { $status='error';$error=crm_text($failure->getMessage(),1000); }
    $entries=[['kind'=>'avito_chat','id'=>$chatId],['kind'=>'auto_greeting'],['kind'=>'sender','name'=>'ЭФТ']];
    $db->prepare("INSERT IGNORE INTO crm_communications (id,site_id,activity_type,channel,direction,external_key,subject,body,is_read,attachments_json,delivery_status,delivery_error,occurred_at) VALUES (?,?,'message','avito','outgoing',?,'Приветствие ЭФТ',?,1,?,?,?,NOW())")->execute([crm_uuid(),$rows[0]['site_id'],$external,$text,json_encode($entries,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$status,$error]);
    $db->prepare('UPDATE crm_settings SET setting_value=? WHERE setting_key=?')->execute([$status,$claim]);
    $db->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
}
