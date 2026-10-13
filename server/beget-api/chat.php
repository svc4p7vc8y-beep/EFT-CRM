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
        'authorName' => $row['display_name'] ?: 'Бывший сотрудник', 'body' => $row['body'], 'createdAt' => $row['created_at']];
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
    if ($action === 'chat.list' && $method === 'GET') {
        crm_chat_all_thread();
        $users = array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => $row['display_name'], 'employeeId' => $row['employee_id'] ?: ''],
            $db->query('SELECT id, display_name, employee_id FROM crm_users WHERE active=1 ORDER BY display_name')->fetchAll());
        $statement = $db->prepare("SELECT t.id, t.thread_type, t.title, t.context_id, t.updated_at, COALESCE(m.last_read_id,0) AS last_read_id,
            (SELECT body FROM crm_chat_messages x WHERE x.thread_id=t.id ORDER BY x.id DESC LIMIT 1) AS last_body,
            (SELECT MAX(x.id) FROM crm_chat_messages x WHERE x.thread_id=t.id) AS last_message_id,
            (SELECT COUNT(*) FROM crm_chat_messages x WHERE x.thread_id=t.id AND x.id>COALESCE(m.last_read_id,0) AND (x.author_user_id IS NULL OR x.author_user_id<>?)) AS unread
            FROM crm_chat_threads t LEFT JOIN crm_chat_members m ON m.thread_id=t.id AND m.user_id=?
            WHERE t.thread_type='all' OR m.user_id IS NOT NULL ORDER BY t.updated_at DESC LIMIT 200");
        $statement->execute([$userId, $userId]); $rows = $statement->fetchAll();
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
        $threadId = (string)($_GET['threadId'] ?? ''); crm_chat_thread($threadId, $user);
        $before = filter_var($_GET['before'] ?? null, FILTER_VALIDATE_INT);
        $statement = $db->prepare('SELECT m.id, m.thread_id, m.author_user_id, m.body, m.created_at, u.display_name FROM crm_chat_messages m LEFT JOIN crm_users u ON u.id=m.author_user_id WHERE m.thread_id=?' . ($before && $before > 0 ? ' AND m.id<?' : '') . ' ORDER BY m.id DESC LIMIT 101');
        $statement->execute($before && $before > 0 ? [$threadId, $before] : [$threadId]);
        $rows = $statement->fetchAll(); $hasMore = count($rows) > 100;
        crm_json(['ok' => true, 'messages' => array_map('crm_chat_public_message', array_reverse(array_slice($rows, 0, 100))), 'hasMore' => $hasMore]);
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
        crm_require_origin(); crm_csrf(); $input = crm_input(32000);
        $threadId = crm_required_text($input['threadId'] ?? '', 'чат', 36);
        $body = crm_required_text($input['body'] ?? '', 'сообщение', 5000);
        $thread = crm_chat_thread($threadId, $user);
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO crm_chat_messages (thread_id, author_user_id, body) VALUES (?, ?, ?)')->execute([$threadId, $userId, $body]);
            $messageId = (int)$db->lastInsertId();
            $db->prepare('UPDATE crm_chat_threads SET updated_at=NOW() WHERE id=?')->execute([$threadId]);
            $member = $db->prepare('INSERT INTO crm_chat_members (thread_id, user_id, last_read_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_read_id=GREATEST(last_read_id, VALUES(last_read_id))');
            $member->execute([$threadId, $userId, $messageId]);
            $recipients = $thread['thread_type'] === 'all'
                ? $db->query('SELECT id FROM crm_users WHERE active=1')->fetchAll(PDO::FETCH_COLUMN)
                : ($db->prepare('SELECT u.id FROM crm_chat_members m JOIN crm_users u ON u.id=m.user_id AND u.active=1 WHERE m.thread_id=?'));
            if ($recipients instanceof PDOStatement) { $recipients->execute([$threadId]); $recipients = $recipients->fetchAll(PDO::FETCH_COLUMN); }
            foreach ($recipients as $recipientId) if ((int)$recipientId !== $userId) {
                crm_push_emit((int)$recipientId, 'message', $thread['title'], $user['displayName'] . ': ' . $body, '#chat/' . $threadId, 'chat:' . $messageId);
            }
            $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        crm_audit($userId, 'chat.send', 'chat', $threadId, ['messageId' => $messageId]);
        try { crm_push_dispatch(12); } catch (Throwable $error) { error_log('EFT CRM chat push failed: ' . $error->getMessage()); }
        crm_json(['ok' => true, 'message' => ['id' => $messageId, 'threadId' => $threadId, 'authorId' => $userId,
            'authorName' => $user['displayName'], 'body' => $body, 'createdAt' => date('Y-m-d H:i:s')]]);
    }
    if ($action === 'chat.read' && $method === 'POST') {
        crm_require_origin(); crm_csrf(); $input = crm_input(2048);
        $threadId = crm_required_text($input['threadId'] ?? '', 'чат', 36);
        crm_chat_thread($threadId, $user);
        $statement = $db->prepare('SELECT COALESCE(MAX(id),0) FROM crm_chat_messages WHERE thread_id=?'); $statement->execute([$threadId]);
        $latest = (int)$statement->fetchColumn();
        $db->prepare('INSERT INTO crm_chat_members (thread_id, user_id, last_read_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_read_id=GREATEST(last_read_id, VALUES(last_read_id))')->execute([$threadId, $userId, $latest]);
        $db->prepare('UPDATE crm_notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND target_path=? AND read_at IS NULL')->execute([$userId, '#chat/' . $threadId]);
        crm_json(['ok' => true, 'lastReadId' => $latest]);
    }
    crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Действие чата не найдено.'], 404);
}
