<?php
declare(strict_types=1);

function crm_chat_all_thread(): string {
    $db = crm_db();
    $id = crm_uuid();
    $db->prepare("INSERT IGNORE INTO crm_chat_threads (id, thread_type, title, direct_key, created_by) VALUES (?, 'all', 'Общий чат ЭФТ', 'all', NULL)")->execute([$id]);
    $row = $db->query("SELECT id FROM crm_chat_threads WHERE direct_key='all' LIMIT 1")->fetchColumn();
    return (string)$row;
}

function crm_chat_thread(string $id, array $user): array {
    if (!preg_match('/^[A-Za-z0-9_-]{1,36}$/', $id)) crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Чат не найден.'], 404);
    $statement = crm_db()->prepare('SELECT t.* FROM crm_chat_threads t LEFT JOIN crm_chat_members m ON m.thread_id=t.id AND m.user_id=? WHERE t.id=? AND (t.thread_type=\'all\' OR m.user_id IS NOT NULL) LIMIT 1');
    $statement->execute([(int)$user['id'], $id]);
    $thread = $statement->fetch();
    if (!$thread) crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Чат не найден или нет доступа.'], 404);
    return $thread;
}

function crm_chat_participants(mixed $value, int $authorId): array {
    if (!is_array($value) || count($value) > 100) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте участников чата.'], 422);
    $ids = [];
    foreach ($value as $item) {
        $id = filter_var($item, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте участников чата.'], 422);
        if ($id !== $authorId) $ids[$id] = $id;
    }
    if (count($ids) > 50) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'В чате может быть не более 50 выбранных участников.'], 422);
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $statement = crm_db()->prepare("SELECT id FROM crm_users WHERE active=1 AND id IN ($marks)");
        $statement->execute(array_values($ids));
        if (count($statement->fetchAll()) !== count($ids)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Один из участников больше не имеет доступа к CRM.'], 422);
    }
    return array_values($ids);
}

function crm_chat_context(string $type, string $contextId, array $user): string {
    if (!preg_match('/^[A-Za-z0-9_-]{1,36}$/', $contextId)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Задача или заявка не найдена.'], 422);
    if ($type === 'task') {
        $statement = crm_db()->prepare('SELECT title, assignee_id FROM crm_tasks WHERE id=?');
        $statement->execute([$contextId]); $row = $statement->fetch();
        if (!$row) crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Задача не найдена.'], 404);
        if (!crm_can($user, 'tasks.manage') && !crm_can($user, 'tasks.view') && (!$user['employeeId'] || $user['employeeId'] !== $row['assignee_id'])) crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Нет доступа к задаче.'], 403);
        return 'Задача: ' . crm_text($row['title'], 160);
    }
    if (!crm_can($user, 'clients.view') && !crm_can($user, 'clients.manage')) crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Нет доступа к заявке.'], 403);
    $statement = crm_db()->prepare('SELECT c.display_name, s.name FROM crm_leads l JOIN crm_sites s ON s.id=l.site_id JOIN crm_clients c ON c.id=s.client_id WHERE l.id=?');
    $statement->execute([$contextId]); $row = $statement->fetch();
    if (!$row) crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Заявка не найдена.'], 404);
    return 'Заявка: ' . crm_text($row['display_name'] . ' · ' . $row['name'], 160);
}

function crm_chat_public_message(array $row): array {
    return ['id' => (int)$row['id'], 'threadId' => $row['thread_id'], 'authorId' => (int)$row['author_user_id'],
        'authorName' => $row['display_name'] ?: 'Бывший сотрудник', 'body' => $row['body'], 'createdAt' => $row['created_at'], 'attachments' => []];
}

function crm_chat_uploads(): array {
    if (!isset($_FILES['files'])) return [];
    $group = $_FILES['files'];
    if (!is_array($group['name'] ?? null) || count($group['name']) > 5) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'К сообщению можно приложить до пяти файлов.'], 422);
    $allowed = [
        'image/jpeg' => ['jpg','jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp'], 'image/heic' => ['heic'], 'image/heif' => ['heif'],
        'application/pdf' => ['pdf'], 'text/plain' => ['txt'],
        'application/zip' => ['zip','docx','xlsx'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
    ];
    $items = []; $total = 0;
    foreach ($group['name'] as $index => $originalName) {
        if ((int)($group['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) crm_json(['ok' => false, 'code' => 'upload_failed', 'message' => 'Не удалось загрузить один из файлов.'], 422);
        $size = (int)($group['size'][$index] ?? 0); $total += $size;
        if ($size < 1 || $size > 10 * 1024 * 1024 || $total > 25 * 1024 * 1024) crm_json(['ok' => false, 'code' => 'payload_too_large', 'message' => 'Один файл — до 10 МБ, все вложения — до 25 МБ.'], 413);
        $temporary = (string)($group['tmp_name'][$index] ?? '');
        if (!is_uploaded_file($temporary)) crm_json(['ok' => false, 'code' => 'upload_failed', 'message' => 'Файл не прошёл проверку загрузки.'], 422);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
        $extension = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
        if (!isset($allowed[$mime]) || !in_array($extension, $allowed[$mime], true)) crm_json(['ok' => false, 'code' => 'invalid_file_type', 'message' => 'Разрешены JPG, PNG, WEBP, HEIC, PDF, TXT, ZIP, DOCX и XLSX.'], 422);
        if ($extension === 'jpeg') $extension = 'jpg';
        $items[] = ['temporary' => $temporary, 'key' => 'c-' . bin2hex(random_bytes(16)) . '.' . $extension,
            'name' => crm_text($originalName, 220), 'mime' => $mime, 'size' => $size];
    }
    return $items;
}

function crm_chat_title(array $row, array $members, array $names, int $currentUserId): string {
    if ($row['thread_type'] !== 'direct') return $row['title'];
    foreach ($members[$row['id']] ?? [] as $id) {
        if ($id !== $currentUserId) return $names[$id] ?? $row['title'];
    }
    return $row['title'];
}

function crm_chat_handle(string $action, string $method): void {
    $user = crm_user(); crm_schema_ensure_v30();
    $db = crm_db(); $userId = (int)$user['id'];
    if ($action === 'chat.file' && $method === 'GET') {
        $key = (string)($_GET['key'] ?? '');
        if (!preg_match('/^c-[a-f0-9]{32}\.(jpg|png|webp|heic|heif|pdf|txt|zip|docx|xlsx)$/', $key)) crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Файл не найден.'], 404);
        $lookup = $db->prepare('SELECT f.original_name, f.mime_type, f.size_bytes, m.thread_id FROM crm_chat_files f JOIN crm_chat_messages m ON m.id=f.message_id WHERE f.storage_key=? LIMIT 1');
        $lookup->execute([$key]); $file = $lookup->fetch();
        if (!$file) crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Файл не найден.'], 404);
        crm_chat_thread((string)$file['thread_id'], $user);
        $path = dirname(__DIR__) . '/uploads/chat/' . $key;
        if (!is_file($path)) crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Файл не найден.'], 404);
        $inline = in_array($file['mime_type'], ['image/jpeg','image/png','image/webp'], true);
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($file['original_name']));
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        readfile($path); exit;
    }
    if ($action === 'chat.list' && $method === 'GET') {
        crm_chat_all_thread();
        $users = array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => $row['display_name'], 'employeeId' => $row['employee_id'] ?: ''],
            $db->query('SELECT id, display_name, employee_id FROM crm_users WHERE active=1 ORDER BY display_name')->fetchAll());
        $statement = $db->prepare("SELECT t.id, t.thread_type, t.title, t.context_id, t.updated_at, COALESCE(m.last_read_id,0) AS last_read_id, COALESCE(m.last_delivered_id,0) AS last_delivered_id,
            (SELECT CASE WHEN body='' THEN 'Вложение' ELSE body END FROM crm_chat_messages x WHERE x.thread_id=t.id ORDER BY x.id DESC LIMIT 1) AS last_body,
            (SELECT MAX(x.id) FROM crm_chat_messages x WHERE x.thread_id=t.id) AS last_message_id,
            (SELECT COUNT(*) FROM crm_chat_messages x WHERE x.thread_id=t.id AND x.id>COALESCE(m.last_read_id,0) AND (x.author_user_id IS NULL OR x.author_user_id<>?)) AS unread
            FROM crm_chat_threads t LEFT JOIN crm_chat_members m ON m.thread_id=t.id AND m.user_id=?
            WHERE t.thread_type='all' OR m.user_id IS NOT NULL ORDER BY t.updated_at DESC LIMIT 200");
        $statement->execute([$userId, $userId]); $rows = $statement->fetchAll();
        $markDelivered = $db->prepare('INSERT INTO crm_chat_members (thread_id, user_id, last_delivered_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_delivered_id=GREATEST(last_delivered_id, VALUES(last_delivered_id))');
        foreach ($rows as $row) if ((int)$row['last_message_id'] > (int)$row['last_delivered_id']) $markDelivered->execute([$row['id'], $userId, (int)$row['last_message_id']]);
        $ids = array_column($rows, 'id'); $members = [];
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $lookup = $db->prepare("SELECT thread_id, user_id FROM crm_chat_members WHERE thread_id IN ($marks)");
            $lookup->execute($ids);
            foreach ($lookup->fetchAll() as $member) $members[$member['thread_id']][] = (int)$member['user_id'];
        }
        $allIds = array_column($users, 'id'); $userNames = array_column($users, 'name', 'id');
        $threads = array_map(static fn(array $row): array => ['id' => $row['id'], 'type' => $row['thread_type'],
            'title' => crm_chat_title($row, $members, $userNames, $userId),
            'contextId' => $row['context_id'] ?: '', 'updatedAt' => $row['updated_at'], 'lastBody' => $row['last_body'] ?: '',
            'lastMessageId' => (int)$row['last_message_id'], 'unread' => (int)$row['unread'],
            'memberIds' => $row['thread_type'] === 'all' ? $allIds : ($members[$row['id']] ?? [])], $rows);
        crm_json(['ok' => true, 'users' => $users, 'threads' => $threads]);
    }
    if ($action === 'chat.messages' && $method === 'GET') {
        $threadId = (string)($_GET['threadId'] ?? ''); $thread = crm_chat_thread($threadId, $user);
        $before = filter_var($_GET['before'] ?? null, FILTER_VALIDATE_INT);
        $statement = $db->prepare('SELECT m.id, m.thread_id, m.author_user_id, m.body, m.created_at, u.display_name FROM crm_chat_messages m LEFT JOIN crm_users u ON u.id=m.author_user_id WHERE m.thread_id=?' . ($before && $before > 0 ? ' AND m.id<?' : '') . ' ORDER BY m.id DESC LIMIT 101');
        $statement->execute($before && $before > 0 ? [$threadId, $before] : [$threadId]);
        $rows = $statement->fetchAll(); $hasMore = count($rows) > 100;
        $messages = array_map('crm_chat_public_message', array_reverse(array_slice($rows, 0, 100)));
        if ($messages) {
            $ids = array_column($messages, 'id'); $marks = implode(',', array_fill(0, count($ids), '?'));
            $files = $db->prepare("SELECT message_id, storage_key, original_name, mime_type, size_bytes FROM crm_chat_files WHERE message_id IN ($marks) ORDER BY created_at,id");
            $files->execute($ids); $byMessage = [];
            foreach ($files->fetchAll() as $file) $byMessage[(int)$file['message_id']][] = [
                'key' => $file['storage_key'], 'name' => $file['original_name'], 'mime' => $file['mime_type'], 'size' => (int)$file['size_bytes']];
            foreach ($messages as &$message) $message['attachments'] = $byMessage[$message['id']] ?? [];
            unset($message);
        }
        $receipts = $db->prepare($thread['thread_type'] === 'all'
            ? 'SELECT u.id AS user_id, u.display_name, COALESCE(m.last_delivered_id,0) AS last_delivered_id, COALESCE(m.last_read_id,0) AS last_read_id FROM crm_users u LEFT JOIN crm_chat_members m ON m.user_id=u.id AND m.thread_id=? WHERE u.active=1 ORDER BY u.display_name'
            : 'SELECT u.id AS user_id, u.display_name, m.last_delivered_id, m.last_read_id FROM crm_chat_members m JOIN crm_users u ON u.id=m.user_id AND u.active=1 WHERE m.thread_id=? ORDER BY u.display_name');
        $receipts->execute([$threadId]);
        crm_json(['ok' => true, 'messages' => $messages, 'hasMore' => $hasMore,
            'receipts' => array_map(static fn(array $row): array => ['userId' => (int)$row['user_id'], 'name' => $row['display_name'],
                'deliveredId' => (int)$row['last_delivered_id'], 'readId' => (int)$row['last_read_id']], $receipts->fetchAll())]);
    }
    if ($action === 'chat.create' && $method === 'POST') {
        crm_require_origin(); crm_csrf(); $input = crm_input(12000);
        $type = (string)($input['type'] ?? '');
        if (!in_array($type, ['all','group','direct','task','lead'], true)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Выберите вид чата.'], 422);
        if ($type === 'all') crm_json(['ok' => true, 'threadId' => crm_chat_all_thread()]);
        $participantIds = crm_chat_participants($input['userIds'] ?? [], $userId);
        if (!$participantIds) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Выберите хотя бы одного получателя.'], 422);
        $contextId = in_array($type, ['task','lead'], true) ? (string)($input['contextId'] ?? '') : '';
        if (in_array($type, ['task','lead'], true) && $contextId === '') crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Выберите задачу или заявку.'], 422);
        $title = $type === 'group' ? crm_required_text($input['title'] ?? '', 'название чата', 180) : '';
        $directKey = null;
        if ($type === 'direct') {
            if (count($participantIds) !== 1) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Для личного чата выберите одного сотрудника.'], 422);
            $pair = [$userId, $participantIds[0]]; sort($pair, SORT_NUMERIC);
            $directKey = 'direct:' . implode(':', $pair);
            $statement = $db->prepare('SELECT display_name FROM crm_users WHERE id=?'); $statement->execute([$participantIds[0]]);
            $title = (string)$statement->fetchColumn();
        }
        if ($contextId !== '') $title = crm_chat_context($type, $contextId, $user);
        $threadId = crm_uuid();
        $db->beginTransaction();
        try {
            $db->prepare('INSERT IGNORE INTO crm_chat_threads (id, thread_type, title, context_id, direct_key, created_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$threadId, $type, $title, $contextId ?: null, $directKey, $userId]);
            if ($directKey !== null || $contextId !== '') {
                $lookup = $db->prepare('SELECT id FROM crm_chat_threads WHERE ' . ($directKey !== null ? 'direct_key=?' : 'thread_type=? AND context_id=?'));
                $lookup->execute($directKey !== null ? [$directKey] : [$type, $contextId]);
                $threadId = (string)$lookup->fetchColumn();
            }
            $insert = $db->prepare('INSERT IGNORE INTO crm_chat_members (thread_id, user_id) VALUES (?, ?)');
            foreach ([$userId, ...$participantIds] as $id) $insert->execute([$threadId, $id]);
            $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        crm_audit($userId, 'chat.create', 'chat', $threadId, ['type' => $type]);
        crm_json(['ok' => true, 'threadId' => $threadId]);
    }
    if ($action === 'chat.send' && $method === 'POST') {
        crm_require_origin(); crm_csrf();
        $multipart = str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data');
        $input = $multipart ? $_POST : crm_input(32000);
        $uploads = $multipart ? crm_chat_uploads() : [];
        $threadId = crm_required_text($input['threadId'] ?? '', 'чат', 36);
        $body = crm_text($input['body'] ?? '', 5000);
        if ($body === '' && !$uploads) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Введите сообщение или прикрепите файл.'], 422);
        $thread = crm_chat_thread($threadId, $user);
        $stored = []; $attachments = [];
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO crm_chat_messages (thread_id, author_user_id, body) VALUES (?, ?, ?)')->execute([$threadId, $userId, $body]);
            $messageId = (int)$db->lastInsertId();
            if ($uploads) {
                $directory = dirname(__DIR__) . '/uploads/chat';
                if (!is_dir($directory) || !is_file($directory . '/.htaccess')) throw new RuntimeException('Chat file storage is unavailable');
                $insertFile = $db->prepare('INSERT INTO crm_chat_files (id, message_id, storage_key, original_name, mime_type, size_bytes) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($uploads as $upload) {
                    $path = $directory . '/' . $upload['key'];
                    if (!move_uploaded_file($upload['temporary'], $path)) throw new RuntimeException('Cannot store chat attachment');
                    $stored[] = $path;
                    $name = $upload['name'] ?: 'Файл';
                    $insertFile->execute([crm_uuid(), $messageId, $upload['key'], $name, $upload['mime'], $upload['size']]);
                    $attachments[] = ['key' => $upload['key'], 'name' => $name, 'mime' => $upload['mime'], 'size' => $upload['size']];
                }
            }
            $db->prepare('UPDATE crm_chat_threads SET updated_at=NOW() WHERE id=?')->execute([$threadId]);
            $member = $db->prepare('INSERT INTO crm_chat_members (thread_id, user_id, last_delivered_id, last_read_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_delivered_id=GREATEST(last_delivered_id, VALUES(last_delivered_id)), last_read_id=GREATEST(last_read_id, VALUES(last_read_id))');
            $member->execute([$threadId, $userId, $messageId, $messageId]);
            $recipients = $thread['thread_type'] === 'all'
                ? $db->query('SELECT id FROM crm_users WHERE active=1')->fetchAll(PDO::FETCH_COLUMN)
                : ($db->prepare('SELECT u.id FROM crm_chat_members m JOIN crm_users u ON u.id=m.user_id AND u.active=1 WHERE m.thread_id=?'));
            if ($recipients instanceof PDOStatement) { $recipients->execute([$threadId]); $recipients = $recipients->fetchAll(PDO::FETCH_COLUMN); }
            $preview = $body !== '' ? $body : 'Файл: ' . $attachments[0]['name'];
            foreach ($recipients as $recipientId) if ((int)$recipientId !== $userId) {
                crm_push_emit((int)$recipientId, 'message', $thread['title'], $user['displayName'] . ': ' . $preview, '#chat/' . $threadId, 'chat:' . $messageId);
            }
            $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); foreach ($stored as $path) @unlink($path); throw $error; }
        crm_audit($userId, 'chat.send', 'chat', $threadId, ['messageId' => $messageId, 'files' => count($attachments)]);
        try { crm_push_dispatch(12); } catch (Throwable $error) { error_log('EFT CRM chat push failed: ' . $error->getMessage()); }
        crm_json(['ok' => true, 'message' => ['id' => $messageId, 'threadId' => $threadId, 'authorId' => $userId,
            'authorName' => $user['displayName'], 'body' => $body, 'createdAt' => date('Y-m-d H:i:s'), 'attachments' => $attachments]]);
    }
    if ($action === 'chat.read' && $method === 'POST') {
        crm_require_origin(); crm_csrf(); $input = crm_input(2048);
        $threadId = crm_required_text($input['threadId'] ?? '', 'чат', 36);
        crm_chat_thread($threadId, $user);
        $messageId = filter_var($input['messageId'] ?? null, FILTER_VALIDATE_INT);
        if ($messageId === false || $messageId < 1) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Сообщение не найдено.'], 422);
        $statement = $db->prepare('SELECT id FROM crm_chat_messages WHERE thread_id=? AND id=?'); $statement->execute([$threadId, $messageId]);
        $latest = (int)$statement->fetchColumn();
        if (!$latest) crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Сообщение не найдено.'], 404);
        $db->prepare('INSERT INTO crm_chat_members (thread_id, user_id, last_delivered_id, last_read_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_delivered_id=GREATEST(last_delivered_id, VALUES(last_delivered_id)), last_read_id=GREATEST(last_read_id, VALUES(last_read_id))')->execute([$threadId, $userId, $latest, $latest]);
        $db->prepare('UPDATE crm_notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND target_path=? AND read_at IS NULL')->execute([$userId, '#chat/' . $threadId]);
        crm_json(['ok' => true, 'lastReadId' => $latest]);
    }
    crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Действие чата не найдено.'], 404);
}
