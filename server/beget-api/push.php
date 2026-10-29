<?php
declare(strict_types=1);

function crm_push_library(): void {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('Web Push library is unavailable.');
    require_once $autoload;
}

function crm_push_keys(): array {
    crm_schema_ensure_v30();
    $db = crm_db();
    $row = $db->query('SELECT public_key, private_key FROM crm_push_keys WHERE id=1')->fetch();
    if ($row) return $row;
    crm_push_library();
    $pair = \Minishlink\WebPush\VAPID::createVapidKeys();
    $insert = $db->prepare('INSERT IGNORE INTO crm_push_keys (id, public_key, private_key) VALUES (1, ?, ?)');
    $insert->execute([$pair['publicKey'], $pair['privateKey']]);
    $row = $db->query('SELECT public_key, private_key FROM crm_push_keys WHERE id=1')->fetch();
    if (!$row) throw new RuntimeException('Could not initialize Web Push keys.');
    return $row;
}

function crm_push_endpoint_allowed(string $endpoint): bool {
    if (strlen($endpoint) > 2048 || strlen($endpoint) < 40) return false;
    $parts = parse_url($endpoint);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return false;
    if (isset($parts['port']) && $parts['port'] !== 443) return false;
    $host = strtolower((string)($parts['host'] ?? ''));
    return $host === 'fcm.googleapis.com' || $host === 'fcm-xm.googleapis.com'
        || $host === 'updates.push.services.mozilla.com'
        || $host === 'web.push.apple.com' || str_ends_with($host, '.push.apple.com')
        || str_ends_with($host, '.notify.windows.com');
}

function crm_push_key_valid(string $key, int $bytes): bool {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $key)) return false;
    $decoded = base64_decode(strtr($key, '-_', '+/'), true);
    return is_string($decoded) && strlen($decoded) === $bytes;
}

function crm_push_preferences(int $userId): array {
    $statement = crm_db()->prepare('SELECT task_enabled, message_enabled, reminder_enabled, all_lead_messages_enabled, all_events_enabled FROM crm_notification_preferences WHERE user_id=?');
    $statement->execute([$userId]);
    $row = $statement->fetch() ?: ['task_enabled' => 1, 'message_enabled' => 1, 'reminder_enabled' => 1, 'all_lead_messages_enabled' => 0, 'all_events_enabled' => 0];
    return ['task' => (bool)$row['task_enabled'], 'message' => (bool)$row['message_enabled'], 'reminder' => (bool)$row['reminder_enabled'], 'allLeadMessages' => (bool)$row['all_lead_messages_enabled'], 'allEvents' => (bool)$row['all_events_enabled']];
}

function crm_push_emit_all(string $kind, string $title, string $body, string $targetPath, string $eventKey, int $authorUserId = 0): void {
    // Private chat notifications are intentionally never broadcast.
    if (str_starts_with($targetPath, '#chat/') || str_starts_with($eventKey, 'test:')) return;
    $capability = str_starts_with($targetPath, '#task/') || $targetPath === '#tasks' ? 'tasks.view' : 'clients.view';
    $users = crm_db()->query('SELECT u.id, u.role FROM crm_users u JOIN crm_notification_preferences p ON p.user_id=u.id WHERE u.active=1 AND p.all_events_enabled=1');
    foreach ($users->fetchAll() as $recipient) {
        if ((int)$recipient['id'] === $authorUserId) continue;
        if (!crm_can($recipient, $capability) && !crm_can($recipient, $capability === 'tasks.view' ? 'tasks.manage' : 'clients.manage')) continue;
        crm_push_emit((int)$recipient['id'], $kind, $title, $body, $targetPath, $eventKey);
    }
}

function crm_push_emit(int $userId, string $kind, string $title, string $body, string $targetPath, string $eventKey): bool {
    if (!in_array($kind, ['task', 'message', 'reminder'], true) || !preg_match('~^#(?:tasks|task/[A-Za-z0-9_-]{1,36}|communications|client/[A-Za-z0-9%_-]+|chat/[A-Za-z0-9_-]{1,36})$~', $targetPath)) return false;
    $statement = crm_db()->prepare('INSERT IGNORE INTO crm_notifications (user_id, kind, title, body, target_path, event_key) VALUES (?, ?, ?, ?, ?, ?)');
    $statement->execute([$userId, $kind, mb_substr($title, 0, 180), mb_substr($body, 0, 300), $targetPath, mb_substr($eventKey, 0, 190)]);
    return $statement->rowCount() === 1;
}

function crm_push_emit_for_employee(string $employeeId, string $kind, string $title, string $body, string $targetPath, string $eventKey): void {
    if ($employeeId === '') return;
    $statement = crm_db()->prepare('SELECT id FROM crm_users WHERE employee_id=? AND active=1');
    $statement->execute([$employeeId]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $userId) crm_push_emit((int)$userId, $kind, $title, $body, $targetPath, $eventKey);
}

function crm_push_notify_lead_activity(string $siteId, string $eventId, string $title, string $body, array $recipientIds = [], int $authorUserId = 0): void {
    if ($siteId === '') return;
    $lead = crm_db()->prepare('SELECT id, owner_employee_id FROM crm_leads WHERE site_id=? ORDER BY created_at DESC LIMIT 1');
    $lead->execute([$siteId]);
    $row = $lead->fetch();
    if (!$row) return;
    $target = '#client/' . rawurlencode((string)$row['id']);
    $eventKey = 'lead-message:' . $eventId;
    $ownerId = (string)($row['owner_employee_id'] ?? '');
    if ($ownerId === '') {
        $site = crm_db()->prepare('SELECT manager_employee_id FROM crm_sites WHERE id=?');
        $site->execute([$siteId]);
        $ownerId = (string)($site->fetchColumn() ?: '');
    }
    $employeeIds = array_values(array_unique(array_filter([...$recipientIds, $ownerId])));
    if ($employeeIds) {
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $recipients = crm_db()->prepare("SELECT id FROM crm_users WHERE active=1 AND employee_id IN ($placeholders)");
        $recipients->execute($employeeIds);
        foreach ($recipients->fetchAll(PDO::FETCH_COLUMN) as $userId) if ((int)$userId !== $authorUserId) crm_push_emit((int)$userId, 'message', $title, $body, $target, $eventKey);
    }
    $all = crm_db()->query('SELECT u.id,u.role FROM crm_users u JOIN crm_notification_preferences p ON p.user_id=u.id WHERE u.active=1 AND (p.all_lead_messages_enabled=1 OR p.all_events_enabled=1)');
    foreach ($all->fetchAll() as $recipient) if ((int)$recipient['id'] !== $authorUserId && (crm_can($recipient, 'clients.view') || crm_can($recipient, 'clients.manage'))) crm_push_emit((int)$recipient['id'], 'message', $title, $body, $target, $eventKey);
}

function crm_push_notify_site(string $siteId, string $eventId): void {
    if ($siteId === '') return;
    $lead = crm_db()->prepare('SELECT id FROM crm_leads WHERE site_id=? ORDER BY created_at DESC LIMIT 1');
    $lead->execute([$siteId]);
    if ($lead->fetchColumn()) {
        crm_push_notify_lead_activity($siteId, $eventId, 'Новое сообщение клиента', 'Откройте заявку для ответа.');
        return;
    }
    $statement = crm_db()->prepare('SELECT manager_employee_id FROM crm_sites WHERE id=?');
    $statement->execute([$siteId]);
    $employeeId = (string)($statement->fetchColumn() ?: '');
    $assigned = false;
    if ($employeeId !== '') {
        $check = crm_db()->prepare('SELECT 1 FROM crm_users WHERE employee_id=? AND active=1 LIMIT 1');
        $check->execute([$employeeId]);
        $assigned = (bool)$check->fetchColumn();
    }
    if (!$assigned) {
        $statement = crm_db()->prepare('SELECT owner_employee_id FROM crm_leads WHERE site_id=? ORDER BY created_at DESC LIMIT 1');
        $statement->execute([$siteId]);
        $employeeId = (string)($statement->fetchColumn() ?: '');
    }
    crm_push_emit_for_employee($employeeId, 'message', 'Новое сообщение клиента', 'Откройте Центр общения для ответа.', '#communications', 'message:' . $eventId);
}

function crm_push_due_reminders(): int {
    crm_schema_ensure_v30();
    $rows = crm_db()->query("SELECT id, assignee_id, title, due_at FROM crm_tasks WHERE assignee_id IS NOT NULL AND status NOT IN ('done') AND due_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 HOUR) ORDER BY due_at LIMIT 200")->fetchAll();
    foreach ($rows as $row) {
        crm_push_emit_for_employee((string)$row['assignee_id'], 'reminder', 'Скоро срок задачи', (string)$row['title'], '#task/' . $row['id'], 'due:' . $row['id'] . ':' . $row['due_at']);
        crm_push_emit_all('reminder', 'Скоро срок задачи', (string)$row['title'], '#task/' . $row['id'], 'due:' . $row['id'] . ':' . $row['due_at']);
    }
    return count($rows);
}

function crm_push_dispatch(int $limit = 12, ?string $eventKey = null): int {
    crm_schema_ensure_v30();
    try { crm_push_library(); $keys = crm_push_keys(); }
    catch (Throwable $error) { error_log('EFT CRM push setup failed: ' . $error->getMessage()); return 0; }
    $db = crm_db();
    $claimed = [];
    $db->beginTransaction();
    try {
        $sql = 'SELECT id, user_id, kind, title, body, target_path FROM crm_notifications WHERE push_sent_at IS NULL AND push_attempts < 3 AND next_attempt_at <= NOW()';
        if ($eventKey !== null) $sql .= ' AND event_key=?';
        $sql .= ' ORDER BY id LIMIT ' . max(1, min(50, $limit)) . ' FOR UPDATE';
        $query = $db->prepare($sql);
        $query->execute($eventKey === null ? [] : [$eventKey]);
        $claimed = $query->fetchAll();
        $mark = $db->prepare('UPDATE crm_notifications SET push_attempts=push_attempts+1, next_attempt_at=DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id=?');
        foreach ($claimed as $row) $mark->execute([(int)$row['id']]);
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    if (!$claimed) return 0;
    $webPush = new \Minishlink\WebPush\WebPush(['VAPID' => [
        'subject' => 'mailto:sale@eftsip.ru', 'publicKey' => $keys['public_key'], 'privateKey' => $keys['private_key'],
    ]], ['TTL' => 3600, 'urgency' => 'normal']);
    $subscriptions = $db->prepare('SELECT id, endpoint, p256dh, auth_key FROM crm_push_subscriptions WHERE user_id=? AND active=1 ORDER BY updated_at DESC, id DESC LIMIT 20');
    $deactivate = $db->prepare('UPDATE crm_push_subscriptions SET active=0 WHERE id=?');
    $complete = $db->prepare('UPDATE crm_notifications SET push_sent_at=NOW() WHERE id=?');
    $sent = 0;
    foreach ($claimed as $row) {
        $prefs = crm_push_preferences((int)$row['user_id']);
        if (!$prefs[$row['kind']] && !$prefs['allEvents']) { $complete->execute([(int)$row['id']]); continue; }
        $subscriptions->execute([(int)$row['user_id']]);
        $devices = $subscriptions->fetchAll();
        if (!$devices) { $complete->execute([(int)$row['id']]); continue; }
        $payload = json_encode(['title' => $row['title'], 'body' => $row['body'], 'url' => $row['target_path'], 'tag' => 'eft-' . $row['id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $failed = false;
        foreach ($devices as $device) {
            try {
                $subscription = \Minishlink\WebPush\Subscription::create(['endpoint' => $device['endpoint'], 'keys' => ['p256dh' => $device['p256dh'], 'auth' => $device['auth_key']]]);
                $report = $webPush->sendOneNotification($subscription, $payload, ['TTL' => $row['kind'] === 'message' ? 86400 : 3600, 'urgency' => $row['kind'] === 'message' ? 'high' : 'normal']);
                if ($report->isSubscriptionExpired()) $deactivate->execute([(int)$device['id']]);
                elseif (!$report->isSuccess()) $failed = true;
                else $sent++;
            } catch (Throwable $error) { $failed = true; error_log('EFT CRM push delivery failed: ' . $error->getMessage()); }
        }
        if (!$failed) $complete->execute([(int)$row['id']]);
    }
    return $sent;
}

function crm_push_list(int $userId): array {
    crm_schema_ensure_v30();
    $statement = crm_db()->prepare('SELECT id, kind, title, body, target_path, created_at, read_at FROM crm_notifications WHERE user_id=? ORDER BY id DESC LIMIT 60');
    $statement->execute([$userId]);
    $rows = array_map(static fn(array $row): array => [
        'id' => (int)$row['id'], 'kind' => $row['kind'], 'title' => $row['title'], 'body' => $row['body'], 'target' => $row['target_path'],
        'createdAt' => $row['created_at'], 'read' => $row['read_at'] !== null,
    ], $statement->fetchAll());
    $unread = crm_db()->prepare('SELECT COUNT(*) FROM crm_notifications WHERE user_id=? AND read_at IS NULL');
    $unread->execute([$userId]);
    $leadUnread = crm_db()->prepare("SELECT COUNT(*) FROM crm_notifications WHERE user_id=? AND kind='message' AND target_path LIKE '#client/%' AND read_at IS NULL");
    $leadUnread->execute([$userId]);
    return ['items' => $rows, 'unread' => (int)$unread->fetchColumn(), 'leadUnread' => (int)$leadUnread->fetchColumn()];
}
