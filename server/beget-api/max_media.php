<?php
declare(strict_types=1);

function crm_max_image_attachment(array $attachment): array {
    $fallback = ['kind' => 'max_image_unavailable', 'name' => 'Фото из MAX недоступно'];
    $payload = is_array($attachment['payload'] ?? null) ? $attachment['payload'] : [];
    $url = (string)($payload['url'] ?? '');
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || !$host || !(preg_match('/(^|\.)oneme\.ru$/', $host) || preg_match('/(^|\.)max\.ru$/', $host)) || !function_exists('curl_init')) return $fallback;
    $directory = dirname(__DIR__) . '/uploads/communications';
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) return $fallback;
    $temporary = tempnam($directory, 'max-');
    if ($temporary === false) return $fallback;
    $stream = fopen($temporary, 'wb');
    if ($stream === false) { unlink($temporary); return $fallback; }
    $size = 0;
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($stream, &$size): int {
            $length = strlen($chunk);
            if ($size + $length > 50 * 1024 * 1024) return 0;
            $written = fwrite($stream, $chunk); $size += (int)$written; return (int)$written;
        }]);
    curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    fclose($stream);
    if ($status !== 200 || $size === 0) { unlink($temporary); return $fallback; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime]) || @getimagesize($temporary) === false) { unlink($temporary); return $fallback; }
    $key = 'm-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!rename($temporary, $directory . '/' . $key)) { unlink($temporary); return $fallback; }
    return ['key' => $key, 'name' => 'Фото из MAX.' . $extensions[$mime], 'mime' => $mime, 'size' => $size, 'url' => '/api/api.php?action=communications.file&key=' . rawurlencode($key)];
}

function crm_max_recover_images_for_row(array $row, array $config): array {
    $entries = json_decode((string)($row['attachments_json'] ?? '[]'), true);
    if (!is_array($entries)) $entries = [];
    $chatId = '';
    $needsRecovery = false;
    foreach ($entries as $entry) {
        if (!is_array($entry)) continue;
        if (($entry['kind'] ?? '') === 'max_chat') $chatId = (string)($entry['id'] ?? '');
        if (($entry['kind'] ?? '') === 'max_image_unavailable' || (isset($entry['key']) && str_starts_with((string)($entry['mime'] ?? ''), 'image/') && !is_file(dirname(__DIR__) . '/uploads/communications/' . basename((string)$entry['key'])))) $needsRecovery = true;
    }
    if (!$needsRecovery) return ['recovered' => 0, 'remaining' => 0, 'changed' => false];
    if (!preg_match('/^-?\d{1,20}$/', $chatId) || empty($config['bot_token'])) throw new RuntimeException('Нет данных для повторной загрузки MAX.');
    $mid = (string)($row['external_key'] ?? '');
    if ($mid === '') throw new RuntimeException('Не найден идентификатор сообщения MAX.');
    $message = crm_http_get_json('https://platform-api2.max.ru/messages/' . rawurlencode($mid), ['Authorization: ' . $config['bot_token']]);
    $body = is_array($message['body'] ?? null) ? $message['body'] : [];
    if ((string)($body['mid'] ?? '') !== $mid || (string)($message['recipient']['chat_id'] ?? '') !== $chatId) throw new RuntimeException('MAX вернул другое сообщение.');
    $images = array_values(array_filter(is_array($body['attachments'] ?? null) ? $body['attachments'] : [], static fn($item): bool => is_array($item) && ($item['type'] ?? '') === 'image'));
    $imageIndex = 0; $recovered = 0; $remaining = 0; $changed = false; $failedHosts = [];
    foreach ($entries as &$entry) {
        if (!is_array($entry)) continue;
        $unavailable = ($entry['kind'] ?? '') === 'max_image_unavailable';
        $storedImage = isset($entry['key']) && str_starts_with((string)($entry['mime'] ?? ''), 'image/');
        if (!$unavailable && !$storedImage) continue;
        $source = $images[$imageIndex++] ?? null;
        if ($storedImage && is_file(dirname(__DIR__) . '/uploads/communications/' . basename((string)$entry['key']))) continue;
        $replacement = $source ? crm_max_image_attachment($source) : ['kind' => 'max_image_unavailable', 'name' => 'Фото из MAX недоступно'];
        if (($replacement['kind'] ?? '') === 'max_image_unavailable') {
            $remaining++;
            $host = strtolower((string)parse_url((string)($source['payload']['url'] ?? ''), PHP_URL_HOST));
            $failedHosts[] = $host !== '' ? $host : 'no-url';
        }
        else $recovered++;
        if ($replacement !== $entry) { $entry = $replacement; $changed = true; }
    }
    unset($entry);
    if ($changed) {
        $statement = crm_db()->prepare('UPDATE crm_communications SET attachments_json=? WHERE id=?');
        $statement->execute([json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row['id']]);
        crm_db()->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
    }
    return ['recovered' => $recovered, 'remaining' => $remaining, 'changed' => $changed, 'failedHosts' => array_values(array_unique($failedHosts))];
}

function crm_max_file_attachment(array $attachment): array {
    $fallback = ['kind'=>'max_file_unavailable','name'=>crm_text($attachment['filename'] ?? $attachment['payload']['filename'] ?? 'Файл из MAX',220)];
    $payload = is_array($attachment['payload'] ?? null) ? $attachment['payload'] : [];
    $url = (string)($payload['url'] ?? '');
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port']!==443)) return $fallback;
    if (($parts['scheme'] ?? '') !== 'https' || !$host || !(preg_match('/(^|\.)oneme\.ru$/', $host) || preg_match('/(^|\.)max\.ru$/', $host)) || !function_exists('curl_init')) return $fallback;
    $directory = dirname(__DIR__) . '/uploads/communications';
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) return $fallback;
    $temporary = tempnam($directory, 'max-');
    if ($temporary === false) return $fallback;
    $stream = fopen($temporary, 'wb');
    if ($stream === false) { unlink($temporary); return $fallback; }
    $size = 0;
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($stream, &$size): int {
            $length = strlen($chunk);
            if ($size + $length > 50 * 1024 * 1024) return 0;
            $written = fwrite($stream, $chunk); $size += (int)$written; return (int)$written;
        }]);
    curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    fclose($stream);
    if ($status !== 200 || $size === 0) { unlink($temporary); return $fallback; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    $name=crm_text($attachment['filename'] ?? $payload['filename'] ?? 'Файл из MAX',220);
    $extensions=['application/pdf'=>'pdf','text/plain'=>'txt','application/zip'=>'zip','application/x-zip-compressed'=>'zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    if($mime==='application/octet-stream' && in_array($ext,['skp','dwg','dxf','ifc'],true))$extensions[$mime]=$ext;
    if (!isset($extensions[$mime])) { unlink($temporary); return $fallback; }
    $key = 'm-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!rename($temporary, $directory . '/' . $key)) { unlink($temporary); return $fallback; }
    return ['key' => $key, 'name' => $name, 'mime' => $mime, 'size' => $size, 'url' => '/api/api.php?action=communications.file&key=' . rawurlencode($key)];
}


function crm_max_message_body(array $message): array {
    $body = is_array($message['body'] ?? null) ? $message['body'] : [];
    // Forwarded documents live in link.message, rather than the outer body.
    $forward = ($message['link']['type'] ?? '') === 'forward' && is_array($message['link']['message'] ?? null) ? $message['link']['message'] : [];
    $attachments = array_merge((array)($body['attachments'] ?? []), (array)($forward['attachments'] ?? []));
    $text = trim((string)($body['text'] ?? ''));
    $forwardText = trim((string)($forward['text'] ?? ''));
    if ($forwardText !== '' && $forwardText !== $text) $text = trim($text . "\n" . $forwardText);
    foreach ($attachments as $attachment) {
        if (($attachment['type'] ?? '') !== 'share') continue;
        $url = (string)($attachment['payload']['url'] ?? '');
        if (filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http','https'], true) && !str_contains($text, $url)) $text = trim($text . "\n" . $url);
    }
    return array_merge($body, ['text'=>$text, 'attachments'=>$attachments]);
}

function crm_max_sync_files(array $config): array {
    $db=crm_db();$chats=[];
    foreach((array)($config['site_chat_ids'] ?? []) as $siteId=>$chatId)$chats[(string)$chatId]=(string)$siteId;
    foreach($db->query("SELECT site_id,attachments_json FROM crm_communications WHERE channel='max' ORDER BY occurred_at DESC LIMIT 1000")->fetchAll() as $row)foreach((array)json_decode((string)$row['attachments_json'],true) as $entry)if(($entry['kind'] ?? '')==='max_chat')$chats[(string)$entry['id']]=(string)($row['site_id'] ?? '');
    $recovered=0;$failed=0;
    foreach(array_slice($chats,0,30,true) as $chatId=>$siteId) {
        if(!preg_match('/^-?\d{1,20}$/',(string)$chatId))continue;
        $data=crm_http_get_json('https://platform-api2.max.ru/messages?chat_id='.rawurlencode((string)$chatId).'&count=100',['Authorization: '.$config['bot_token']]);
        foreach((array)($data['messages'] ?? []) as $message) {
            if(!is_array($message) || !empty($message['sender']['is_bot']))continue;
            $body=crm_max_message_body($message);$mid=(string)($body['mid'] ?? '');if($mid==='')continue;
            $media=array_values(array_filter((array)($body['attachments'] ?? []),static fn($a)=>is_array($a)&&in_array($a['type'] ?? '',['file','image'],true)));if(!$media && empty($message['link']) && !array_filter((array)($body['attachments'] ?? []),static fn($a)=>($a['type'] ?? '')==='share'))continue;
            $q=$db->prepare("SELECT id,site_id,body,attachments_json FROM crm_communications WHERE channel='max' AND external_key=? LIMIT 1");$q->execute([$mid]);$existing=$q->fetch();
            $entries=$existing?(json_decode((string)$existing['attachments_json'],true) ?: []):[];
            if($existing && count(array_filter($entries,static fn($a)=>!empty($a['key'])))>=count($media) && (string)$existing['body']===crm_text($body['text'] ?? '',20000))continue;
            $entries=array_values(array_filter($entries,static fn($a)=>!in_array($a['kind'] ?? '',['max_file_unavailable','max_image_unavailable'],true)));
            $stored=count(array_filter($entries,static fn($a)=>!empty($a['key'])));
            foreach(array_slice($media,$stored) as $a) {$file=($a['type'] ?? '')==='image'?crm_max_image_attachment($a):crm_max_file_attachment($a);$entries[]=$file;if(!empty($file['key']))$recovered++;else $failed++;}
            $sender=crm_text($message['sender']['name'] ?? $message['sender']['first_name'] ?? 'Клиент MAX',100);
            if($existing)$db->prepare('UPDATE crm_communications SET attachments_json=?,body=? WHERE id=?')->execute([json_encode($entries,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),crm_text($body['text'] ?? 'Файл из MAX',20000),$existing['id']]);
            else {
                $target=$siteId ?: crm_external_conversation_site('max',(string)$chatId,$sender,'Обращение из MAX');
                $entries[]=['kind'=>'max_chat','id'=>(string)$chatId];$entries[]=['kind'=>'sender','name'=>$sender];
                $id=crm_uuid();$time=(int)floor((float)($message['timestamp'] ?? 0)/1000);if($time<946684800||$time>time()+86400)$time=time();
                $db->prepare("INSERT IGNORE INTO crm_communications (id,site_id,activity_type,channel,direction,external_key,body,is_read,attachments_json,delivery_status,occurred_at) VALUES (?,?,'message','max','incoming',?,?,0,?,'delivered',?)")->execute([$id,$target,$mid,crm_text($body['text'] ?? 'Файл из MAX',20000),json_encode($entries,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),date('Y-m-d H:i:s',$time)]);
                if($db->query('SELECT ROW_COUNT()')->fetchColumn())crm_push_notify_site($target,$id);
            }
        }
    }
    $db->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
    return ['recovered'=>$recovered,'failed'=>$failed];
}
