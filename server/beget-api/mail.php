<?php
declare(strict_types=1);

function crm_mail_header(string $value): string {
    return trim(str_replace(["\r", "\n"], ' ', $value));
}

function crm_mail_encoded(string $value): string {
    return '=?UTF-8?B?' . base64_encode(crm_mail_header($value)) . '?=';
}

function crm_mail_message(string $from, string $sender, string $to, string $subject, string $body, array $files): string {
    $from = crm_mail_header($from);
    $to = crm_mail_header($to);
    $boundary = 'eft-' . bin2hex(random_bytes(12));
    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s +0000'),
        'From: ' . crm_mail_encoded($sender) . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Reply-To: <' . $from . '>',
        'Subject: ' . crm_mail_encoded($subject !== '' ? $subject : 'Сообщение от ЭФТ'),
        'MIME-Version: 1.0',
    ];
    if (!$files) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
    }
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    $parts = ['--' . $boundary, 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64', '', chunk_split(base64_encode($body), 76, "\r\n")];
    foreach ($files as $file) {
        $name = crm_mail_header((string)$file['name']);
        $content = file_get_contents((string)$file['path']);
        if ($content === false) throw new RuntimeException('Не удалось прочитать вложение.');
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: ' . (string)$file['mime'] . '; name="' . crm_mail_encoded($name) . '"';
        $parts[] = 'Content-Disposition: attachment; filename="' . crm_mail_encoded($name) . '"';
        $parts[] = 'Content-Transfer-Encoding: base64';
        $parts[] = '';
        $parts[] = chunk_split(base64_encode($content), 76, "\r\n");
    }
    $parts[] = '--' . $boundary . '--';
    return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts) . "\r\n";
}

function crm_mail_send_smtp(array $config, string $to, string $subject, string $body, array $files): void {
    $host = (string)($config['smtp_host'] ?? '');
    $port = (int)($config['smtp_port'] ?? 465);
    $user = (string)($config['smtp_user'] ?? '');
    $password = (string)($config['smtp_password'] ?? '');
    $from = (string)($config['from'] ?? '');
    if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !in_array($port, [465, 587], true) || $user === '' || $password === '') {
        throw new RuntimeException('Проверьте адрес SMTP, порт, логин и пароль приложения в настройках сервера.');
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Проверьте адрес отправителя и получателя.');
    if (!function_exists('curl_init')) throw new RuntimeException('На сервере недоступен модуль cURL.');
    $message = crm_mail_message($from, (string)($config['sender_name'] ?? 'ЭФТ'), $to, $subject, $body, $files);
    $offset = 0;
    $curl = curl_init(($port === 465 ? 'smtps://' : 'smtp://') . $host . ':' . $port);
    curl_setopt_array($curl, [
        CURLOPT_USERNAME => $user, CURLOPT_PASSWORD => $password,
        CURLOPT_MAIL_FROM => '<' . $from . '>', CURLOPT_MAIL_RCPT => ['<' . $to . '>'],
        CURLOPT_USE_SSL => CURLUSESSL_ALL, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_UPLOAD => true, CURLOPT_INFILESIZE => strlen($message), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
        CURLOPT_READFUNCTION => static function ($handle, $stream, int $length) use ($message, &$offset): string {
            $chunk = substr($message, $offset, $length);
            $offset += strlen($chunk);
            return $chunk;
        },
    ]);
    $result = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($result === false || $status < 200 || $status >= 300) {
        error_log('EFT CRM SMTP error: ' . $status . ' ' . $error);
        throw new RuntimeException('Почтовый сервер не принял письмо. Проверьте настройки SMTP и пароль приложения.');
    }
}

function crm_mail_decode_header(string $value): string {
    if (!function_exists('imap_mime_header_decode')) return crm_mail_header($value);
    $parts = imap_mime_header_decode($value);
    if (!is_array($parts)) return crm_mail_header($value);
    $text = '';
    foreach ($parts as $part) {
        $charset = (string)($part->charset ?? 'default');
        $chunk = (string)($part->text ?? '');
        try { $text .= $charset === 'default' ? $chunk : (mb_convert_encoding($chunk, 'UTF-8', $charset) ?: $chunk); }
        catch (Throwable $error) { $text .= $chunk; }
    }
    return trim($text);
}

function crm_mail_part_text($imap, int $uid, object $structure, string $section = ''): array {
    if (!empty($structure->parts)) {
        $plain = ''; $html = '';
        foreach ($structure->parts as $index => $part) {
            [$nextPlain, $nextHtml] = crm_mail_part_text($imap, $uid, $part, $section === '' ? (string)($index + 1) : $section . '.' . ($index + 1));
            $plain .= $nextPlain; $html .= $nextHtml;
        }
        return [$plain, $html];
    }
    if ((int)($structure->type ?? -1) !== 0 || (!empty($structure->ifdisposition) && strtoupper((string)($structure->disposition ?? '')) === 'ATTACHMENT')) return ['', ''];
    $raw = $section === '' ? imap_body($imap, $uid, FT_UID | FT_PEEK) : imap_fetchbody($imap, $uid, $section, FT_UID | FT_PEEK);
    if (!is_string($raw)) return ['', ''];
    $decoded = match ((int)($structure->encoding ?? 0)) { 3 => base64_decode($raw, true) ?: '', 4 => quoted_printable_decode($raw), default => $raw };
    $charset = 'UTF-8';
    foreach (($structure->parameters ?? []) as $parameter) if (strtolower((string)($parameter->attribute ?? '')) === 'charset') $charset = (string)$parameter->value;
    if (strtoupper($charset) !== 'UTF-8') {
        try { $decoded = mb_convert_encoding($decoded, 'UTF-8', $charset) ?: $decoded; }
        catch (Throwable $error) { /* Keep the original text when an email declares an unknown charset. */ }
    }
    return strtoupper((string)($structure->subtype ?? 'PLAIN')) === 'HTML' ? ['', $decoded] : [$decoded, ''];
}

function crm_mail_sync(array $config): array {
    $host = (string)($config['imap_host'] ?? '');
    $port = (int)($config['imap_port'] ?? 993);
    $user = (string)($config['imap_user'] ?? '');
    $password = (string)($config['imap_password'] ?? '');
    if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || $port !== 993 || $user === '' || $password === '') {
        throw new RuntimeException('Укажите адрес IMAP, логин и пароль приложения на сервере.');
    }
    if (!function_exists('imap_open')) throw new RuntimeException('На сервере недоступен модуль IMAP.');
    $imap = @imap_open('{' . $host . ':' . $port . '/imap/ssl/validate-cert}INBOX', $user, $password, OP_READONLY, 1);
    if (!$imap) {
        error_log('EFT CRM IMAP connection failed: ' . implode('; ', imap_errors() ?: []));
        throw new RuntimeException('Не удалось подключиться к почтовому ящику. Проверьте IMAP и пароль приложения.');
    }
    $imported = 0; $unassigned = 0;
    try {
        $since = (new DateTimeImmutable('-30 days'))->format('d-M-Y');
        $uids = imap_search($imap, 'SINCE "' . $since . '"', SE_UID) ?: [];
        rsort($uids, SORT_NUMERIC);
        $uids = array_slice($uids, 0, 250);
        $db = crm_db();
        $exists = $db->prepare("SELECT 1 FROM crm_communications WHERE channel='email' AND external_key=? LIMIT 1");
        $sites = $db->prepare('SELECT s.id FROM crm_clients c JOIN crm_sites s ON s.client_id=c.id WHERE LOWER(c.email)=? ORDER BY s.created_at DESC LIMIT 2');
        $insert = $db->prepare("INSERT IGNORE INTO crm_communications (id,site_id,task_id,activity_type,channel,direction,external_key,subject,body,is_read,attachments_json,delivery_status,delivery_error,occurred_at,author_id,author_employee_id) VALUES (?,?,NULL,'email','email','incoming',?,?,?,0,?,'delivered','',?,NULL,NULL)");
        foreach ($uids as $uid) {
            $overview = imap_fetch_overview($imap, (string)$uid, FT_UID);
            if (!$overview || !isset($overview[0])) continue;
            $mail = $overview[0];
            $messageId = trim((string)($mail->message_id ?? ''));
            $key = 'imap-' . hash('sha256', mb_strtolower($user) . '|' . ($messageId ?: (string)$uid));
            $exists->execute([$key]); if ($exists->fetchColumn()) continue;
            $from = imap_rfc822_parse_adrlist((string)($mail->from ?? ''), '');
            $address = isset($from[0]->mailbox, $from[0]->host) ? mb_strtolower($from[0]->mailbox . '@' . $from[0]->host) : '';
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) continue;
            $sites->execute([$address]); $matches = $sites->fetchAll(PDO::FETCH_COLUMN);
            $siteId = count($matches) === 1 ? (string)$matches[0] : null;
            $structure = imap_fetchstructure($imap, $uid, FT_UID);
            if (!$structure) continue;
            [$plain, $html] = crm_mail_part_text($imap, $uid, $structure);
            $content = trim($plain !== '' ? $plain : html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $content = mb_substr($content !== '' ? $content : '[Письмо без текста]', 0, 20000);
            $subject = mb_substr(crm_mail_decode_header((string)($mail->subject ?? '')), 0, 500);
            $date = date('Y-m-d H:i:s', strtotime((string)($mail->date ?? 'now')) ?: time());
            $metadata = json_encode([['kind' => 'sender', 'email' => $address]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $insert->execute([crm_uuid(), $siteId, $key, $subject, $content, $metadata, $date]);
            if ($insert->rowCount() === 1) { $imported++; if ($siteId === null) $unassigned++; }
        }
        if ($imported) $db->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
    } finally { imap_close($imap); }
    return ['imported' => $imported, 'unassigned' => $unassigned, 'scanned' => count($uids)];
}
