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
