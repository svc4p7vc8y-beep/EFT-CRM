<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/chat.php';

crm_headers();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string)($_GET['action'] ?? 'health');

if (str_starts_with($action, 'chat.')) crm_chat_handle($action, $method);

function crm_task_assignee_ids(array $task): array {
    $ids = json_decode((string)($task['assignee_ids_json'] ?? '[]'), true);
    return is_array($ids) && $ids ? array_values(array_unique(array_filter($ids, 'is_string'))) : (empty($task['assignee_id']) ? [] : [(string)$task['assignee_id']]);
}

if ($action === 'health' && $method === 'GET') {
    crm_db()->query('SELECT 1');
    crm_json(['ok' => true, 'service' => 'eft-crm-api', 'time' => gmdate('c')]);
}

if ($action === 'connectors.telegram.webhook' && $method === 'POST') {
    $config=crm_integration_config('telegram');
    $expected=(string)($config['webhook_secret']??'');
    $received=(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'');
    if ($expected==='' || !hash_equals($expected,$received)) crm_json(['ok'=>false],403);
    $raw=file_get_contents('php://input');
    if ($raw===false || strlen($raw)>1024*1024) crm_json(['ok'=>false],413);
    $update=json_decode($raw,true); if (!is_array($update)) crm_json(['ok'=>false],422);
    $message=$update['message']??null;
    if (!is_array($message)) crm_json(['ok'=>true]);
    $chatId=(string)($message['chat']['id']??'');
    $text=trim((string)($message['text']??''));
    if (!preg_match('/^-?\d{1,20}$/',$chatId) || $text==='') crm_json(['ok'=>true]);
    if (preg_match('/^\/start(?:@[A-Za-z0-9_]+)?\s+([A-Za-z0-9_-]{20,64})$/',$text,$matches)) {
        $links=is_array($config['pending_links']??null)?$config['pending_links']:[];
        $token=$matches[1]; $link=$links[$token]??null;
        if (is_array($link) && (int)($link['expires']??0)>=time()) {
            $siteId=(string)($link['siteId']??'');
            $site=crm_db()->prepare('SELECT 1 FROM crm_sites WHERE id=?'); $site->execute([$siteId]);
            if ($site->fetchColumn()) {
                $map=is_array($config['site_chat_ids']??null)?$config['site_chat_ids']:[];
                $map[$siteId]=$chatId; $config['site_chat_ids']=$map;
                unset($links[$token]); $config['pending_links']=$links;
                crm_store_private_integration('telegram',$config);
                try { crm_http_json('https://api.telegram.org/bot'.$config['bot_token'].'/sendMessage',['chat_id'=>$chatId,'text'=>'Чат подключён к ЭФТ. Теперь ваши сообщения появятся в CRM.']); } catch (Throwable $error) { error_log('EFT CRM Telegram welcome failed'); }
            }
        }
        crm_json(['ok'=>true]);
    }
    $map=is_array($config['site_chat_ids']??null)?$config['site_chat_ids']:[];
    $siteId=array_search($chatId,$map,true);
    if ($siteId===false) crm_json(['ok'=>true]);
    crm_schema_ensure_v30();
    $key='tg-update-'.(string)(int)($update['update_id']??0);
    $sender=(string)($message['from']['first_name']??'Telegram');
    $metadata=json_encode([['kind'=>'sender','name'=>mb_substr($sender,0,100)],['kind'=>'workflow','status'=>'reply','followUpAt'=>'']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $insert=crm_db()->prepare("INSERT IGNORE INTO crm_communications (id,site_id,task_id,activity_type,channel,direction,external_key,subject,body,is_read,attachments_json,delivery_status,delivery_error,occurred_at,author_id,author_employee_id) VALUES (?,?,NULL,'message','telegram','incoming',?,'',?,0,?,'delivered','',?,NULL,NULL)");
    $eventId = crm_uuid();
    $insert->execute([$eventId,$siteId,$key,mb_substr($text,0,20000),$metadata,date('Y-m-d H:i:s',(int)($message['date']??time()))]);
    if ($insert->rowCount()) {
        crm_db()->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
        try { crm_push_notify_site((string)$siteId, $eventId); crm_push_dispatch(2); } catch (Throwable $error) { error_log('EFT CRM Telegram push failed: ' . $error->getMessage()); }
    }
    crm_json(['ok'=>true]);
}

if ($action === 'connectors.max.webhook' && $method === 'POST') {
    $config = crm_integration_config('max');
    $expected = (string)($config['webhook_secret'] ?? '');
    $received = (string)($_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? '');
    if ($expected === '' || !hash_equals($expected, $received)) crm_json(['ok' => false], 403);
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 1024 * 1024) crm_json(['ok' => false], 413);
    $update = json_decode($raw, true);
    if (!is_array($update)) crm_json(['ok' => false], 422);
    $type = (string)($update['update_type'] ?? '');
    if ($type === 'bot_started') {
        $token = (string)($update['payload'] ?? '');
        $chatId = (string)($update['chat_id'] ?? '');
        $links = is_array($config['pending_links'] ?? null) ? $config['pending_links'] : [];
        $link = $links[$token] ?? null;
        if ($token !== '' && preg_match('/^-?\d{1,20}$/', $chatId) && is_array($link) && (int)($link['expires'] ?? 0) >= time()) {
            $siteId = (string)($link['siteId'] ?? '');
            $site = crm_db()->prepare('SELECT 1 FROM crm_sites WHERE id=?'); $site->execute([$siteId]);
            if ($site->fetchColumn()) {
                $map = is_array($config['site_chat_ids'] ?? null) ? $config['site_chat_ids'] : [];
                $otherSite = array_search($chatId, $map, true);
                if ($otherSite !== false && $otherSite !== $siteId) crm_json(['ok' => true]);
                $map[$siteId] = $chatId; $config['site_chat_ids'] = $map;
                unset($links[$token]); $config['pending_links'] = $links;
                crm_store_private_integration('max', $config);
                crm_schema_ensure_v30();
                $rows = crm_db()->query("SELECT id, attachments_json FROM crm_communications WHERE site_id IS NULL AND channel='max'")->fetchAll();
                $backfill = crm_db()->prepare("UPDATE crm_communications SET site_id=? WHERE id=? AND site_id IS NULL AND channel='max'");
                foreach ($rows as $row) {
                    $entries = json_decode((string)$row['attachments_json'], true);
                    foreach (is_array($entries) ? $entries : [] as $entry) {
                        if (is_array($entry) && ($entry['kind'] ?? '') === 'max_chat' && (string)($entry['id'] ?? '') === $chatId) { $backfill->execute([$siteId, $row['id']]); break; }
                    }
                }
                crm_db()->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
                try { crm_http_json('https://platform-api2.max.ru/messages?chat_id=' . rawurlencode($chatId), ['text' => 'Чат подключён к ЭФТ. Ваши сообщения появятся в CRM.', 'notify' => true], ['Authorization: ' . $config['bot_token']]); }
                catch (Throwable $error) { error_log('EFT CRM MAX welcome failed'); }
            }
        }
        crm_json(['ok' => true]);
    }
    if ($type !== 'message_created') crm_json(['ok' => true]);
    $message = $update['message'] ?? null;
    if (!is_array($message) || !empty($message['sender']['is_bot'])) crm_json(['ok' => true]);
    $chatId = (string)($message['recipient']['chat_id'] ?? $update['chat_id'] ?? '');
    $body = is_array($message['body'] ?? null) ? $message['body'] : [];
    $text = trim((string)($body['text'] ?? ''));
    $mid = (string)($body['mid'] ?? '');
    if (!preg_match('/^-?\d{1,20}$/', $chatId) || $text === '' || $mid === '') crm_json(['ok' => true]);
    $map = is_array($config['site_chat_ids'] ?? null) ? $config['site_chat_ids'] : [];
    $siteId = array_search($chatId, $map, true);
    crm_schema_ensure_v30();
    $sender = (string)($message['sender']['first_name'] ?? $message['sender']['name'] ?? 'MAX');
    $metadata = json_encode([['kind' => 'sender', 'name' => mb_substr($sender, 0, 100)], ['kind' => 'max_chat', 'id' => $chatId], ['kind' => 'workflow', 'status' => 'reply', 'followUpAt' => '']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = (int)($message['timestamp'] ?? $update['timestamp'] ?? 0);
    $occurredAt = $timestamp > 0 ? (int)floor($timestamp / 1000) : time();
    $insert = crm_db()->prepare("INSERT IGNORE INTO crm_communications (id,site_id,task_id,activity_type,channel,direction,external_key,subject,body,is_read,attachments_json,delivery_status,delivery_error,occurred_at,author_id,author_employee_id) VALUES (?,?,NULL,'message','max','incoming',?,'',?,0,?,'delivered','',?,NULL,NULL)");
    $eventId = crm_uuid();
    $insert->execute([$eventId, $siteId === false ? null : $siteId, mb_substr($mid, 0, 255), mb_substr($text, 0, 20000), $metadata, date('Y-m-d H:i:s', $occurredAt)]);
    if ($insert->rowCount()) {
        crm_db()->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
        if ($siteId !== false) try { crm_push_notify_site((string)$siteId, $eventId); crm_push_dispatch(2); } catch (Throwable $error) { error_log('EFT CRM MAX push failed: ' . $error->getMessage()); }
    }
    crm_json(['ok' => true]);
}

if ($action === 'login' && $method === 'POST') {
    crm_require_origin();
    crm_session_start();
    $input = crm_input(16384);
    $username = mb_strtolower(crm_required_text($input['username'] ?? '', 'логин', 96));
    $password = (string)($input['password'] ?? '');
    $ipHash = hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)crm_config()['app_secret']);
    $limit = crm_db()->prepare('SELECT COUNT(*) FROM crm_login_attempts WHERE ip_hash = ? AND succeeded = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $limit->execute([$ipHash]);
    if ((int)$limit->fetchColumn() >= 10) crm_json(['ok' => false, 'code' => 'too_many_attempts', 'message' => 'Слишком много попыток. Повторите позже.'], 429);
    $statement = crm_db()->prepare('SELECT id, username, display_name, password_hash, role, employee_id, active FROM crm_users WHERE username = ? LIMIT 1');
    $statement->execute([$username]);
    $row = $statement->fetch();
    $success = $row && (int)$row['active'] === 1 && password_verify($password, (string)$row['password_hash']);
    crm_db()->prepare('INSERT INTO crm_login_attempts (ip_hash, username, succeeded) VALUES (?, ?, ?)')->execute([$ipHash, $username, $success ? 1 : 0]);
    if (!$success) crm_json(['ok' => false, 'code' => 'invalid_credentials', 'message' => 'Неверный логин или пароль.'], 401);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['auth_tag'] = crm_session_tag($row);
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    unset($_SESSION['finance_unlocked']);
    crm_db()->prepare('UPDATE crm_users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
    crm_audit((int)$row['id'], 'auth.login', 'user', (string)$row['id']);
    crm_json(['ok' => true, 'user' => crm_session_user(), 'capabilities' => crm_capabilities((string)$row['role']), 'financeUnlocked' => false, 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'session' && $method === 'GET') {
    crm_session_start();
    $user = crm_session_user();
    if (!$user) crm_json(['ok' => true, 'authenticated' => false]);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    crm_json(['ok' => true, 'authenticated' => true, 'user' => $user, 'capabilities' => crm_capabilities((string)$user['role']), 'financeUnlocked' => crm_finance_unlocked(), 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'logout' && $method === 'POST') {
    crm_require_origin();
    $user = crm_user();
    crm_csrf();
    crm_audit((int)$user['id'], 'auth.logout', 'user', (string)$user['id']);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
    crm_json(['ok' => true]);
}

if ($action === 'notifications.config' && $method === 'GET') {
    $user = crm_user();
    $keys = crm_push_keys();
    $count = crm_db()->prepare('SELECT COUNT(*) FROM crm_push_subscriptions WHERE user_id=? AND active=1');
    $count->execute([(int)$user['id']]);
    crm_json(['ok' => true, 'publicKey' => $keys['public_key'], 'preferences' => crm_push_preferences((int)$user['id']), 'devices' => (int)$count->fetchColumn()]);
}

if ($action === 'notifications.list' && $method === 'GET') {
    $user = crm_user();
    crm_json(array_merge(['ok' => true], crm_push_list((int)$user['id'])));
}

if ($action === 'notifications.subscribe' && $method === 'POST') {
    crm_require_origin(); $user = crm_user(); crm_csrf(); crm_schema_ensure_v30();
    $input = crm_input(8192); $subscription = $input['subscription'] ?? null;
    if (!is_array($subscription)) crm_json(['ok' => false, 'message' => 'Нет подписки устройства.'], 422);
    $endpoint = (string)($subscription['endpoint'] ?? '');
    $p256dh = (string)($subscription['keys']['p256dh'] ?? '');
    $auth = (string)($subscription['keys']['auth'] ?? '');
    if (!crm_push_endpoint_allowed($endpoint) || !crm_push_key_valid($p256dh, 65) || !crm_push_key_valid($auth, 16)) {
        crm_json(['ok' => false, 'message' => 'Браузер передал некорректную подписку.'], 422);
    }
    $statement = crm_db()->prepare('INSERT INTO crm_push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth_key) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), endpoint=VALUES(endpoint), p256dh=VALUES(p256dh), auth_key=VALUES(auth_key), active=1');
    $statement->execute([(int)$user['id'], hash('sha256', $endpoint), $endpoint, $p256dh, $auth]);
    crm_json(['ok' => true]);
}

if ($action === 'notifications.unsubscribe' && $method === 'POST') {
    crm_require_origin(); $user = crm_user(); crm_csrf();
    $input = crm_input(8192); $endpoint = (string)($input['endpoint'] ?? '');
    if ($endpoint !== '') {
        $statement = crm_db()->prepare('DELETE FROM crm_push_subscriptions WHERE user_id=? AND endpoint_hash=?');
        $statement->execute([(int)$user['id'], hash('sha256', $endpoint)]);
    }
    crm_json(['ok' => true]);
}

if ($action === 'notifications.preferences' && $method === 'POST') {
    crm_require_origin(); $user = crm_user(); crm_csrf(); crm_schema_ensure_v30(); $input = crm_input(2048);
    $prefs = [!empty($input['task']) ? 1 : 0, !empty($input['message']) ? 1 : 0, !empty($input['reminder']) ? 1 : 0];
    $statement = crm_db()->prepare('INSERT INTO crm_notification_preferences (user_id, task_enabled, message_enabled, reminder_enabled) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE task_enabled=VALUES(task_enabled), message_enabled=VALUES(message_enabled), reminder_enabled=VALUES(reminder_enabled)');
    $statement->execute([(int)$user['id'], ...$prefs]);
    crm_json(['ok' => true, 'preferences' => crm_push_preferences((int)$user['id'])]);
}

if ($action === 'notifications.read' && $method === 'POST') {
    crm_require_origin(); $user = crm_user(); crm_csrf(); $input = crm_input(2048);
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) crm_json(['ok' => false, 'message' => 'Уведомление не найдено.'], 422);
    $statement = crm_db()->prepare('UPDATE crm_notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?');
    $statement->execute([$id, (int)$user['id']]);
    crm_json(array_merge(['ok' => true], crm_push_list((int)$user['id'])));
}

if ($action === 'notifications.test' && $method === 'POST') {
    crm_require_origin(); $user = crm_user(); crm_csrf(); crm_schema_ensure_v30();
    crm_push_emit((int)$user['id'], 'task', 'Проверка уведомлений ЭФТ', 'Если вы видите это сообщение, уведомления работают.', '#tasks', 'test:' . bin2hex(random_bytes(12)));
    $sent = crm_push_dispatch(2);
    crm_json(array_merge(['ok' => true, 'sent' => $sent], crm_push_list((int)$user['id'])));
}

if ($action === 'tasks.create' && $method === 'POST') {
    crm_require_origin(); $user = crm_user(); crm_csrf(); crm_schema_ensure_v30();
    $managesTasks = crm_can($user, 'tasks.manage');
    if (!$managesTasks && !crm_can($user, 'tasks.self')) crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Нет права создавать задачи.'], 403);
    $input = crm_input(16384); $task = $input['task'] ?? null;
    if (!is_array($task)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Заполните задачу.'], 422);
    $title = crm_required_text($task['title'] ?? '', 'название задачи', 250);
    $description = crm_text($task['description'] ?? '', 20000);
    $question = crm_text($task['question'] ?? '', 5000);
    $assigneeIds = array_values(array_unique(array_filter(array_map(static fn($id) => crm_text($id, 36), is_array($task['assigneeIds'] ?? null) ? $task['assigneeIds'] : [$task['assigneeId'] ?? '']))));
    if (!$managesTasks) {
        if (empty($user['employeeId'])) crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Привяжите аккаунт к сотруднику в настройках.'], 403);
        $assigneeIds = [(string)$user['employeeId']];
    }
    if (count($assigneeIds) > 50) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Слишком много исполнителей.'], 422);
    $assignee = $assigneeIds[0] ?? '';
    if ($question !== '' && !$assigneeIds) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Для вопроса выберите исполнителя.'], 422);
    $status = in_array($task['status'] ?? 'planned', ['planned','doing','review','blocked','done'], true) ? $task['status'] : 'planned';
    $blockReason = crm_text($task['blockReason'] ?? '', 10000);
    if ($status === 'blocked' && $blockReason === '') crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Укажите причину блокировки.'], 422);
    if ($status === 'done' && $question !== '') crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Сначала дождитесь ответа на вопрос.'], 422);
    $quantity = (float)($task['quantity'] ?? 1); $completed = (float)($task['completedQty'] ?? 0);
    if (!is_finite($quantity) || !is_finite($completed) || $quantity <= 0 || $completed < 0 || $completed > $quantity) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте объём задания.'], 422);
    if ($status === 'done') $completed = $quantity;
    $orderId = crm_text($task['orderId'] ?? '', 36); $siteId = crm_text($task['siteId'] ?? '', 36);
    $crewId = crm_text($task['crewId'] ?? '', 36); $stageId = crm_text($task['constructionStageId'] ?? '', 36);
    $db = crm_db(); $db->beginTransaction();
    try {
        $checkAssignee = $db->prepare('SELECT id FROM crm_employees WHERE id=? AND active=1');
        foreach ($assigneeIds as $personId) { $checkAssignee->execute([$personId]); if (!$checkAssignee->fetchColumn()) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Исполнитель не найден.'], 422); }
        if ($orderId !== '') { $q = $db->prepare('SELECT site_id FROM crm_orders WHERE id=?'); $q->execute([$orderId]); $orderSite = $q->fetchColumn(); if (!$orderSite || ($siteId !== '' && $siteId !== $orderSite)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Заказ и объект не совпадают.'], 422); $siteId = (string)$orderSite; }
        if ($siteId !== '') { $q = $db->prepare('SELECT id FROM crm_sites WHERE id=?'); $q->execute([$siteId]); if (!$q->fetchColumn()) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Объект не найден.'], 422); }
        if ($crewId !== '') { $q = $db->prepare('SELECT id FROM crm_crews WHERE id=?'); $q->execute([$crewId]); if (!$q->fetchColumn()) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Бригада не найдена.'], 422); }
        if ($stageId !== '') { $q = $db->prepare('SELECT site_id FROM crm_construction_stages WHERE id=?'); $q->execute([$stageId]); if (!$siteId || $q->fetchColumn() !== $siteId) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Этап не относится к объекту.'], 422); }
        $taskId = crm_uuid();
        $db->prepare('INSERT INTO crm_tasks (id, order_id, title, description, site_id, assignee_id, assignee_ids_json, crew_id, construction_stage_id, status, priority, due_at, quantity, completed_quantity, unit_name, block_reason, checklist_json, original_due_at, reschedule_history_json, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')->execute([$taskId, $orderId ?: null, $title, $description, $siteId ?: null, $assignee ?: null, json_encode($assigneeIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $crewId ?: null, $stageId ?: null, $status, ($task['priority'] ?? '') === 'high' ? 'high' : 'normal', crm_sql_datetime($task['dueAt'] ?? ''), $quantity, $completed, crm_required_text($task['unit'] ?? 'задача', 'единица', 80), $status === 'blocked' ? $blockReason : '', '[]', null, '[]', (int)$user['id']]);
        if ($question !== '') $db->prepare("INSERT INTO crm_task_questions (task_id, question, requester_user_id, answer) VALUES (?, ?, ?, '')")->execute([$taskId, $question, (int)$user['id']]);
        $db->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
        foreach ($assigneeIds as $personId) if ($personId !== (string)($user['employeeId'] ?? '')) crm_push_emit_for_employee($personId, 'task', $question !== '' ? 'Вам задан вопрос' : 'Вам назначена задача', $question !== '' ? $question : $title, '#task/' . $taskId, 'task:create:' . $taskId);
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    crm_audit((int)$user['id'], 'task.create', 'task', $taskId, ['assigneeId' => $assignee]);
    try { crm_push_dispatch(4); } catch (Throwable $error) { error_log('EFT CRM task push failed: ' . $error->getMessage()); }
    crm_json(['ok' => true, 'taskId' => $taskId, 'workspace' => crm_workspace_for_user($user)]);
}

if ($action === 'tasks.answer' && $method === 'POST') {
    crm_require_origin(); $user = crm_user(); crm_csrf(); crm_schema_ensure_v30();
    $input = crm_input(8192);
    $taskId = crm_required_text($input['taskId'] ?? '', 'задачу', 36);
    $answer = crm_required_text($input['answer'] ?? '', 'ответ', 5000);
    $db = crm_db(); $db->beginTransaction();
    try {
        $lookup = $db->prepare('SELECT t.id, t.title, t.assignee_id, t.assignee_ids_json, t.status, q.question, q.answer AS previous_answer, q.requester_user_id FROM crm_tasks t JOIN crm_task_questions q ON q.task_id=t.id WHERE t.id=? FOR UPDATE');
        $lookup->execute([$taskId]); $task = $lookup->fetch();
        if (!$task) { $db->rollBack(); crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Вопрос к задаче не найден.'], 404); }
        if (!crm_can($user, 'tasks.manage') && !in_array((string)($user['employeeId'] ?? ''), crm_task_assignee_ids($task), true)) {
            $db->rollBack(); crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Ответить может назначенный исполнитель.'], 403);
        }
        if ($task['previous_answer'] !== '') { $db->rollBack(); crm_json(['ok' => false, 'code' => 'already_answered', 'message' => 'Ответ уже отправлен. Обновите задачу, чтобы увидеть его.'], 409); }
        $db->prepare('UPDATE crm_task_questions SET answer=?, answered_by_user_id=?, answered_at=NOW() WHERE task_id=?')->execute([$answer, (int)$user['id'], $taskId]);
        $db->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
        if ((int)$task['requester_user_id'] !== (int)$user['id']) {
            crm_push_emit((int)$task['requester_user_id'], 'task', 'Получен ответ на вопрос', $answer, '#task/' . $taskId, 'answer:' . $taskId . ':' . bin2hex(random_bytes(8)));
        }
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    crm_audit((int)$user['id'], 'task.answer', 'task', $taskId, ['requesterUserId' => (int)$task['requester_user_id']]);
    try { crm_push_dispatch(4); } catch (Throwable $error) { error_log('EFT CRM answer push failed: ' . $error->getMessage()); }
    crm_json(['ok' => true, 'workspace' => crm_workspace_for_user($user)]);
}

if ($action === 'tasks.comment' && $method === 'POST') {
    crm_require_origin(); $user = crm_user(); crm_csrf(); crm_schema_ensure_v30();
    $input = crm_input(8192);
    $taskId = crm_required_text($input['taskId'] ?? '', 'задачу', 36);
    $body = crm_required_text($input['text'] ?? '', 'комментарий', 5000);
    $db = crm_db(); $db->beginTransaction();
    try {
        $lookup = $db->prepare('SELECT id, title, site_id, assignee_id, assignee_ids_json, created_by FROM crm_tasks WHERE id=? FOR UPDATE');
        $lookup->execute([$taskId]); $task = $lookup->fetch();
        if (!$task) { $db->rollBack(); crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Задача не найдена.'], 404); }
        if (!crm_can($user, 'tasks.manage') && !in_array((string)($user['employeeId'] ?? ''), crm_task_assignee_ids($task), true)) {
            $db->rollBack(); crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Комментировать задачу может назначенный исполнитель или руководитель.'], 403);
        }
        $id = crm_uuid();
        $insert = $db->prepare("INSERT INTO crm_communications (id,site_id,task_id,activity_type,channel,direction,external_key,subject,body,is_read,attachments_json,delivery_status,delivery_error,occurred_at,author_id,author_employee_id) VALUES (?,?,?,'note','note','internal',?,'',?,1,'[]','internal','',NOW(),?,?)");
        $insert->execute([$id, $task['site_id'] ?: null, $taskId, $id, $body, (int)$user['id'], $user['employeeId'] ?: null]);
        foreach (crm_task_assignee_ids($task) as $personId) if ($personId !== (string)($user['employeeId'] ?? '')) crm_push_emit_for_employee($personId, 'message', 'Комментарий к задаче: ' . $task['title'], $body, '#task/' . $taskId, 'task-comment:' . $id);
        if ((int)$task['created_by'] !== (int)$user['id']) crm_push_emit((int)$task['created_by'], 'message', 'Комментарий к задаче: ' . $task['title'], $body, '#task/' . $taskId, 'task-comment:' . $id);
        $db->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    crm_audit((int)$user['id'], 'task.comment', 'task', $taskId, ['messageId' => $id]);
    try { crm_push_dispatch(10); } catch (Throwable $error) { error_log('EFT CRM task comment push failed: ' . $error->getMessage()); }
    crm_json(['ok' => true, 'workspace' => crm_workspace_for_user($user)]);
}

if ($action === 'tasks.complete' && $method === 'POST') {
    crm_require_origin(); $user = crm_user(); crm_csrf(); crm_schema_ensure_v30();
    $input = crm_input(8192);
    $taskId = crm_required_text($input['taskId'] ?? '', 'задачу', 36);
    $result = crm_required_text($input['text'] ?? '', 'результат выполнения', 5000);
    $db = crm_db(); $db->beginTransaction();
    try {
        $lookup = $db->prepare('SELECT id, site_id, assignee_id, assignee_ids_json, status, quantity, checklist_json FROM crm_tasks WHERE id=? FOR UPDATE');
        $lookup->execute([$taskId]); $task = $lookup->fetch();
        if (!$task) { $db->rollBack(); crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Задача не найдена.'], 404); }
        if (!crm_can($user, 'tasks.manage') && !in_array((string)($user['employeeId'] ?? ''), crm_task_assignee_ids($task), true)) {
            $db->rollBack(); crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Завершить задачу может назначенный исполнитель или руководитель.'], 403);
        }
        if ($task['status'] === 'done') { $db->rollBack(); crm_json(['ok' => false, 'code' => 'already_completed', 'message' => 'Задача уже завершена.'], 409); }
        $checklist = json_decode((string)$task['checklist_json'], true);
        foreach (is_array($checklist) ? $checklist : [] as $item) if (empty($item['done'])) { $db->rollBack(); crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Выполните все пункты чек-листа.'], 422); }
        $question = $db->prepare('SELECT question, answer FROM crm_task_questions WHERE task_id=?');
        $question->execute([$taskId]); $pending = $question->fetch();
        if ($pending && $pending['question'] !== '' && $pending['answer'] === '') { $db->rollBack(); crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Сначала ответьте на вопрос.'], 422); }
        $db->prepare("UPDATE crm_tasks SET status='done', completed_quantity=quantity, block_reason='' WHERE id=?")->execute([$taskId]);
        $id = crm_uuid();
        $insert = $db->prepare("INSERT INTO crm_communications (id,site_id,task_id,activity_type,channel,direction,external_key,subject,body,is_read,attachments_json,delivery_status,delivery_error,occurred_at,author_id,author_employee_id) VALUES (?,?,?,'note','note','internal',?,'completion',?,1,'[]','internal','',NOW(),?,?)");
        $insert->execute([$id, $task['site_id'] ?: null, $taskId, $id, $result, (int)$user['id'], $user['employeeId'] ?: null]);
        $db->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    crm_audit((int)$user['id'], 'task.complete', 'task', $taskId, ['messageId' => $id]);
    crm_json(['ok' => true, 'workspace' => crm_workspace_for_user($user)]);
}

if ($action === 'tasks.check' && $method === 'POST') {
    crm_require_origin(); $user = crm_user(); crm_csrf(); crm_schema_ensure_v30();
    $input = crm_input(4096);
    $taskId = crm_required_text($input['taskId'] ?? '', 'задачу', 36);
    $checkId = crm_text($input['checkId'] ?? '', 36);
    $title = $checkId === '' ? crm_required_text($input['title'] ?? '', 'пункт проверки', 500) : '';
    $db = crm_db(); $db->beginTransaction();
    try {
        $lookup = $db->prepare('SELECT id, site_id, assignee_id, assignee_ids_json, status, checklist_json FROM crm_tasks WHERE id=? FOR UPDATE');
        $lookup->execute([$taskId]); $task = $lookup->fetch();
        if (!$task) { $db->rollBack(); crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Задача не найдена.'], 404); }
        if (!crm_can($user, 'tasks.manage') && !in_array((string)($user['employeeId'] ?? ''), crm_task_assignee_ids($task), true)) {
            $db->rollBack(); crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Чек-лист доступен назначенному исполнителю и руководителю.'], 403);
        }
        if ($task['status'] === 'done') { $db->rollBack(); crm_json(['ok' => false, 'code' => 'already_completed', 'message' => 'Задача уже завершена.'], 409); }
        $checklist = json_decode((string)$task['checklist_json'], true);
        if (!is_array($checklist)) $checklist = [];
        $eventSubject = 'checklist.added';
        if ($checkId !== '') {
            $found = false;
            foreach ($checklist as &$item) if (($item['id'] ?? '') === $checkId) {
                $item['done'] = empty($item['done']); $title = (string)($item['title'] ?? 'Пункт чек-листа');
                $eventSubject = $item['done'] ? 'checklist.done' : 'checklist.reopened';
                $found = true; break;
            }
            unset($item);
            if (!$found) { $db->rollBack(); crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Пункт проверки не найден.'], 404); }
        } else $checklist[] = ['id' => crm_uuid(), 'title' => $title, 'done' => false];
        $db->prepare('UPDATE crm_tasks SET checklist_json=? WHERE id=?')->execute([json_encode($checklist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $taskId]);
        $eventText = $eventSubject === 'checklist.done' ? 'Выполнен пункт чек-листа: ' . $title : ($eventSubject === 'checklist.reopened' ? 'Пункт чек-листа возвращён в работу: ' . $title : 'Добавлен пункт чек-листа: ' . $title);
        $eventId = crm_uuid();
        $insert = $db->prepare("INSERT INTO crm_communications (id,site_id,task_id,activity_type,channel,direction,external_key,subject,body,is_read,attachments_json,delivery_status,delivery_error,occurred_at,author_id,author_employee_id) VALUES (?,?,?,'system','system','internal',?,?,?,1,'[]','internal','',NOW(),?,?)");
        $insert->execute([$eventId, $task['site_id'] ?: null, $taskId, $eventId, $eventSubject, $eventText, (int)$user['id'], $user['employeeId'] ?: null]);
        $db->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    crm_audit((int)$user['id'], 'task.check', 'task', $taskId, ['checkId' => $checkId]);
    crm_json(['ok' => true, 'workspace' => crm_workspace_for_user($user)]);
}

if ($action === 'bootstrap' && $method === 'GET') {
    $user = crm_user();
    crm_schema_ensure_v30();
    $canSeeAll = crm_can($user, 'employees.view') || crm_can($user, 'employees.manage') || crm_can($user, 'attendance.manage') || crm_can($user, 'finance.view');
    if ($canSeeAll) {
        $rows = crm_db()->query('SELECT * FROM crm_employees ORDER BY active DESC, full_name')->fetchAll();
    } elseif ($user['employeeId']) {
        $statement = crm_db()->prepare('SELECT * FROM crm_employees WHERE id = ?');
        $statement->execute([$user['employeeId']]);
        $rows = $statement->fetchAll();
    } else {
        $rows = [];
    }
    $includeFinance = crm_can($user, 'finance.view') && crm_finance_unlocked();
    $includeCrews = crm_can($user, 'crews.view') || crm_can($user, 'crews.manage');
    $includeUsers = crm_can($user, 'users.manage');
    $workspace = crm_workspace_for_user($user);
    $inventory = crm_can($user, 'procurement.view') || crm_can($user, 'procurement.manage') ? crm_public_inventory() : [
        'materials' => [], 'suppliers' => [], 'supplyNeeds' => [], 'purchases' => [], 'stockDocuments' => [], 'tools' => [], 'toolEvents' => [],
        'inventoryRevision' => 0, 'inventoryInitialized' => true,
    ];
    crm_json(array_merge([
        'ok' => true,
        'user' => $user,
        'capabilities' => crm_capabilities((string)$user['role']),
        'financeUnlocked' => $includeFinance,
        'employees' => array_map(static fn(array $row): array => crm_public_employee($row, $includeFinance), $rows),
        'crews' => $includeCrews ? crm_public_crews() : [],
        'users' => $includeUsers ? array_map('crm_public_user', crm_db()->query('SELECT id, username, display_name, role, employee_id, active, last_login_at FROM crm_users ORDER BY active DESC, display_name')->fetchAll()) : [],
    ], $workspace, $inventory));
}

if ($action === 'workspace.save' && $method === 'PUT') {
    crm_require_origin();
    $user = crm_user();
    if (!crm_can($user, 'clients.manage') || !crm_can($user, 'tasks.manage')) crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Недостаточно прав для изменения общего рабочего пространства.'], 403);
    crm_csrf();
    $input = crm_input(8 * 1024 * 1024);
    $baseRevision = filter_var($input['baseRevision'] ?? null, FILTER_VALIDATE_INT);
    if ($baseRevision === false || $baseRevision < 0) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Не удалось определить версию данных. Обновите страницу.'], 422);
    $workspace = crm_workspace_save(is_array($input['workspace'] ?? null) ? $input['workspace'] : [], $user, $baseRevision, !empty($input['initialize']));
    crm_json(array_merge(['ok' => true], crm_workspace_for_user($user)));
}

if ($action === 'clients.merge' && $method === 'POST') {
    crm_require_origin(); $user=crm_require_capability('clients.manage'); crm_csrf(); crm_schema_ensure_v30();
    $input=crm_input(); $targetId=crm_required_text($input['targetId']??'','основной клиент',36); $sourceId=crm_required_text($input['sourceId']??'','дубль клиента',36);
    if ($targetId===$sourceId) crm_json(['ok'=>false,'code'=>'validation_failed','message'=>'Выберите разных клиентов.'],422);
    $db=crm_db(); $db->beginTransaction();
    try {
        $query=$db->prepare('SELECT id,display_name,email,phone,source,notes FROM crm_clients WHERE id IN (?,?) FOR UPDATE'); $query->execute([$targetId,$sourceId]);
        $rows=[]; foreach ($query->fetchAll() as $row) $rows[$row['id']]=$row;
        if (!isset($rows[$targetId],$rows[$sourceId])) throw new RuntimeException('Один из клиентов не найден.');
        $email1=mb_strtolower(trim((string)$rows[$targetId]['email'])); $email2=mb_strtolower(trim((string)$rows[$sourceId]['email']));
        if ($email1==='' || $email1!==$email2) throw new RuntimeException('Для объединения нужен одинаковый адрес электронной почты.');
        $sourceNotes=trim((string)$rows[$sourceId]['notes']);
        $mergedNotes=trim((string)$rows[$targetId]['notes'].($sourceNotes!==''?"\nОбъединено из «".$rows[$sourceId]['display_name']."»: ".$sourceNotes:''));
        $db->prepare('UPDATE crm_clients SET phone=?, source=?, notes=? WHERE id=?')->execute([
            (string)$rows[$targetId]['phone']!==''?$rows[$targetId]['phone']:$rows[$sourceId]['phone'],
            (string)$rows[$targetId]['source']!==''?$rows[$targetId]['source']:$rows[$sourceId]['source'],
            $mergedNotes,$targetId,
        ]);
        $db->prepare('UPDATE crm_sites SET client_id=? WHERE client_id=?')->execute([$targetId,$sourceId]);
        $db->prepare('DELETE FROM crm_clients WHERE id=?')->execute([$sourceId]);
        $db->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); crm_json(['ok'=>false,'code'=>'merge_failed','message'=>$error->getMessage()],422); }
    crm_audit((int)$user['id'],'client.merge','client',$targetId,['sourceId'=>$sourceId]);
    crm_json(['ok'=>true,'workspace'=>crm_workspace_for_user($user)]);
}

if ($action === 'inventory.save' && $method === 'PUT') {
    crm_require_origin();
    $user = crm_require_capability('procurement.manage');
    crm_csrf();
    $input = crm_input(8 * 1024 * 1024);
    $baseRevision = filter_var($input['baseRevision'] ?? null, FILTER_VALIDATE_INT);
    if ($baseRevision === false || $baseRevision < 0) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Не удалось определить версию склада. Обновите страницу.'], 422);
    $inventory = crm_inventory_save(is_array($input['inventory'] ?? null) ? $input['inventory'] : [], $user, $baseRevision);
    crm_json(array_merge(['ok' => true], $inventory));
}

if ($action === 'finance.unlock' && $method === 'POST') {
    crm_require_origin();
    $user = crm_require_capability('finance.view');
    crm_csrf();
    crm_settings_ensure();
    $input = crm_input(16384);
    $pin = preg_replace('/\s+/', '', (string)($input['pin'] ?? ''));
    $attempts = (array)($_SESSION['finance_attempts'] ?? []);
    $attempts = array_values(array_filter($attempts, static fn(int $time): bool => $time > time() - 900));
    if (count($attempts) >= 5) crm_json(['ok' => false, 'code' => 'too_many_attempts', 'message' => 'Слишком много попыток. Повторите через 15 минут.'], 429);
    $statement = crm_db()->prepare('SELECT setting_value FROM crm_settings WHERE setting_key = ?');
    $statement->execute(['attendance_finance_pin_hash']);
    if (!password_verify($pin, (string)$statement->fetchColumn())) {
        $attempts[] = time(); $_SESSION['finance_attempts'] = $attempts;
        crm_json(['ok' => false, 'code' => 'invalid_pin', 'message' => 'Неверный пароль финансовой части.'], 401);
    }
    $_SESSION['finance_unlocked'] = true; unset($_SESSION['finance_attempts']);
    crm_audit((int)$user['id'], 'finance.unlock', 'settings');
    crm_json(['ok' => true, 'financeUnlocked' => true]);
}

if ($action === 'finance.lock' && $method === 'POST') {
    crm_require_origin(); $user = crm_user(); crm_csrf(); unset($_SESSION['finance_unlocked']);
    crm_audit((int)$user['id'], 'finance.lock', 'settings');
    crm_json(['ok' => true, 'financeUnlocked' => false]);
}

if ($action === 'finance.pin.update' && $method === 'POST') {
    crm_require_origin(); $user = crm_require_capability('finance.manage'); crm_csrf(); crm_settings_ensure();
    $input = crm_input(16384);
    $current = preg_replace('/\s+/', '', (string)($input['currentPin'] ?? ''));
    $next = preg_replace('/\s+/', '', (string)($input['newPin'] ?? ''));
    if (!preg_match('/^\d{3,12}$/', $next)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Новый пароль должен содержать от 3 до 12 цифр.'], 422);
    $statement = crm_db()->prepare('SELECT setting_value FROM crm_settings WHERE setting_key = ?'); $statement->execute(['attendance_finance_pin_hash']);
    if (!password_verify($current, (string)$statement->fetchColumn())) crm_json(['ok' => false, 'code' => 'invalid_pin', 'message' => 'Текущий пароль указан неверно.'], 401);
    crm_db()->prepare('UPDATE crm_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?')->execute([password_hash($next, PASSWORD_DEFAULT), (int)$user['id'], 'attendance_finance_pin_hash']);
    $_SESSION['finance_unlocked'] = true; crm_audit((int)$user['id'], 'finance.pin.update', 'settings');
    crm_json(['ok' => true, 'financeUnlocked' => true]);
}

if ($action === 'users.save' && in_array($method, ['POST', 'PUT'], true)) {
    crm_require_origin(); $actor = crm_require_capability('users.manage'); crm_csrf(); $input = crm_input();
    $id = isset($input['id']) && is_numeric($input['id']) ? (int)$input['id'] : 0;
    $username = mb_strtolower(crm_required_text($input['username'] ?? '', 'логин', 96));
    if (!preg_match('/^[a-z0-9._-]{3,96}$/', $username)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Логин: 3–96 латинских букв, цифр, точек, дефисов или подчёркиваний.'], 422);
    $displayName = crm_required_text($input['displayName'] ?? '', 'имя пользователя', 180);
    $roles = ['owner','admin','finance','manager','production','procurement','foreman','employee','viewer'];
    $role = in_array($input['role'] ?? '', $roles, true) ? $input['role'] : 'employee';
    $employeeId = crm_text($input['employeeId'] ?? '', 36) ?: null; $active = !array_key_exists('active', $input) || (bool)$input['active'];
    if ($id === (int)$actor['id'] && !$active) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Нельзя отключить собственную учётную запись.'], 422);
    $password = (string)($input['password'] ?? '');
    if (($id === 0 || $password !== '') && strlen($password) < 8) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Пароль входа должен содержать не меньше 8 символов.'], 422);
    try {
        if ($id) {
            $exists = crm_db()->prepare('SELECT id FROM crm_users WHERE id = ?'); $exists->execute([$id]); if (!$exists->fetchColumn()) crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Учётная запись не найдена.'], 404);
            $sql = 'UPDATE crm_users SET username=?, display_name=?, role=?, employee_id=?, active=?'; $params = [$username,$displayName,$role,$employeeId,$active?1:0];
            if ($password !== '') { $sql .= ', password_hash=?'; $params[] = password_hash($password, PASSWORD_DEFAULT); }
            $sql .= ' WHERE id=?'; $params[] = $id; crm_db()->prepare($sql)->execute($params);
        } else {
            crm_db()->prepare('INSERT INTO crm_users (username, display_name, password_hash, role, employee_id, active) VALUES (?, ?, ?, ?, ?, ?)')->execute([$username,$displayName,password_hash($password, PASSWORD_DEFAULT),$role,$employeeId,$active?1:0]);
            $id = (int)crm_db()->lastInsertId();
        }
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') crm_json(['ok' => false, 'code' => 'duplicate', 'message' => 'Такой логин или сотрудник уже связан с другой учётной записью.'], 409);
        throw $error;
    }
    crm_audit((int)$actor['id'], $method === 'POST' ? 'user.create' : 'user.update', 'user', (string)$id, ['passwordChanged' => $password !== '']);
    $saved = crm_db()->prepare('SELECT id, username, display_name, role, employee_id, active, last_login_at FROM crm_users WHERE id = ?'); $saved->execute([$id]);
    crm_json(['ok' => true, 'user' => crm_public_user($saved->fetch())]);
}

if ($action === 'crews.save' && in_array($method, ['POST', 'PUT'], true)) {
    crm_require_origin();
    $user = crm_require_capability('crews.manage');
    crm_csrf();
    $input = crm_input();
    $id = crm_text($input['id'] ?? '', 36) ?: crm_uuid();
    $name = crm_required_text($input['name'] ?? '', 'название бригады', 200);
    $memberIds = array_values(array_unique(array_filter(array_map(static fn(mixed $value): string => crm_text($value, 36), is_array($input['memberIds'] ?? null) ? $input['memberIds'] : []))));
    if (count($memberIds) > 100) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'В бригаде не может быть больше 100 сотрудников.'], 422);
    $leadId = crm_text($input['leadId'] ?? '', 36);
    if ($leadId !== '' && !in_array($leadId, $memberIds, true)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Бригадир должен входить в состав бригады.'], 422);
    if ($memberIds) {
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $activeEmployees = crm_db()->prepare("SELECT id FROM crm_employees WHERE id IN ($placeholders)");
        $activeEmployees->execute($memberIds);
        if (count($activeEmployees->fetchAll()) !== count($memberIds)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Один из сотрудников бригады не найден.'], 422);
    }
    $exists = crm_db()->prepare('SELECT id FROM crm_crews WHERE id = ?');
    $exists->execute([$id]);
    $previous = (bool)$exists->fetchColumn();
    $db = crm_db();
    $db->beginTransaction();
    try {
        if ($previous) {
            $statement = $db->prepare('UPDATE crm_crews SET name=?, specialty=?, foreman_id=?, phone=?, notes=?, active=? WHERE id=?');
            $statement->execute([$name, crm_text($input['specialty'] ?? '', 160), $leadId ?: null, crm_text($input['phone'] ?? '', 60), crm_text($input['notes'] ?? '', 10000), !array_key_exists('active', $input) || (bool)$input['active'] ? 1 : 0, $id]);
            $db->prepare('DELETE FROM crm_crew_members WHERE crew_id = ?')->execute([$id]);
        } else {
            $statement = $db->prepare('INSERT INTO crm_crews (id, name, specialty, foreman_id, phone, notes, active) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([$id, $name, crm_text($input['specialty'] ?? '', 160), $leadId ?: null, crm_text($input['phone'] ?? '', 60), crm_text($input['notes'] ?? '', 10000), !array_key_exists('active', $input) || (bool)$input['active'] ? 1 : 0]);
        }
        $insertMember = $db->prepare('INSERT INTO crm_crew_members (crew_id, employee_id) VALUES (?, ?)');
        foreach ($memberIds as $employeeId) $insertMember->execute([$id, $employeeId]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    crm_audit((int)$user['id'], $previous ? 'crew.update' : 'crew.create', 'crew', $id, ['members' => count($memberIds)]);
    $crew = array_values(array_filter(crm_public_crews(), static fn(array $row): bool => $row['id'] === $id))[0];
    crm_json(['ok' => true, 'crew' => $crew]);
}

if ($action === 'employees.save' && in_array($method, ['POST', 'PUT'], true)) {
    crm_require_origin();
    $user = crm_require_capability('employees.manage');
    crm_csrf();
    $input = crm_input();
    $id = crm_text($input['id'] ?? '', 36) ?: crm_uuid();
    $name = crm_required_text($input['name'] ?? '', 'ФИО сотрудника', 200);
    $attendanceMode = in_array($input['attendanceMode'] ?? '', ['hours', 'days'], true) ? $input['attendanceMode'] : 'hours';
    $avatarKey = crm_avatar_key($input['avatarKey'] ?? '');
    $active = !array_key_exists('active', $input) || (bool)$input['active'];
    $existing = crm_db()->prepare('SELECT id, pay_rate_cents, advance_amount_cents FROM crm_employees WHERE id = ?');
    $existing->execute([$id]);
    $previous = $existing->fetch();
    $payRate = $previous ? (int)$previous['pay_rate_cents'] : 0;
    $advance = $previous ? (int)$previous['advance_amount_cents'] : 0;
    if ((isset($input['payRate']) || isset($input['advanceAmount'])) && !crm_can($user, 'finance.manage')) {
        crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Изменять ставки может только финансовая роль.'], 403);
    }
    if (isset($input['payRate']) || isset($input['advanceAmount'])) crm_require_finance_unlocked();
    if (isset($input['payRate'])) $payRate = crm_money_cents($input['payRate']);
    if (isset($input['advanceAmount'])) $advance = crm_money_cents($input['advanceAmount']);
    $values = [$name, crm_text($input['role'] ?? '', 120), crm_text($input['department'] ?? '', 120), crm_text($input['phone'] ?? '', 60), crm_text($input['email'] ?? '', 190), $avatarKey, $attendanceMode, $payRate, $advance, $active ? 1 : 0, crm_text($input['notes'] ?? '', 10000), $id];
    if ($previous) {
        $statement = crm_db()->prepare('UPDATE crm_employees SET full_name=?, role_name=?, department=?, phone=?, email=?, avatar_key=?, attendance_mode=?, pay_rate_cents=?, advance_amount_cents=?, active=?, notes=? WHERE id=?');
    } else {
        $statement = crm_db()->prepare('INSERT INTO crm_employees (full_name, role_name, department, phone, email, avatar_key, attendance_mode, pay_rate_cents, advance_amount_cents, active, notes, id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    }
    $statement->execute($values);
    crm_audit((int)$user['id'], $previous ? 'employee.update' : 'employee.create', 'employee', $id, ['financeChanged' => isset($input['payRate']) || isset($input['advanceAmount'])]);
    $saved = crm_db()->prepare('SELECT * FROM crm_employees WHERE id = ?');
    $saved->execute([$id]);
    crm_json(['ok' => true, 'employee' => crm_public_employee($saved->fetch(), crm_can($user, 'finance.view') && crm_finance_unlocked())]);
}

if ($action === 'employees.photo' && $method === 'POST') {
    crm_require_origin();
    $user = crm_require_capability('employees.manage');
    crm_csrf();
    $employeeId = crm_text($_POST['employeeId'] ?? '', 36);
    $employee = crm_db()->prepare('SELECT * FROM crm_employees WHERE id = ?');
    $employee->execute([$employeeId]);
    $row = $employee->fetch();
    if (!$row) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Сотрудник не найден.'], 422);
    $file = $_FILES['photo'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        crm_json(['ok' => false, 'code' => 'upload_failed', 'message' => 'Не удалось загрузить фотографию. Проверьте размер файла.'], 422);
    }
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > 5 * 1024 * 1024) crm_json(['ok' => false, 'code' => 'payload_too_large', 'message' => 'Фотография должна быть не больше 5 МБ.'], 413);
    $temporary = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($temporary)) crm_json(['ok' => false, 'code' => 'upload_failed', 'message' => 'Файл не прошёл проверку загрузки.'], 422);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) crm_json(['ok' => false, 'code' => 'invalid_file_type', 'message' => 'Разрешены фотографии JPG, PNG и WebP.'], 422);
    $dimensions = @getimagesize($temporary);
    if (!$dimensions || $dimensions[0] < 32 || $dimensions[1] < 32 || $dimensions[0] > 8000 || $dimensions[1] > 8000) {
        crm_json(['ok' => false, 'code' => 'invalid_image', 'message' => 'Файл повреждён или имеет неподходящий размер изображения.'], 422);
    }
    $directory = dirname(__DIR__) . '/uploads/employees';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Cannot create employee upload directory');
    $key = 'e-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($temporary, $directory . '/' . $key)) throw new RuntimeException('Cannot store employee photo');
    crm_db()->prepare('UPDATE crm_employees SET avatar_key = ? WHERE id = ?')->execute([$key, $employeeId]);
    crm_audit((int)$user['id'], 'employee.photo', 'employee', $employeeId, ['mime' => $mime, 'size' => $size]);
    $saved = crm_db()->prepare('SELECT * FROM crm_employees WHERE id = ?');
    $saved->execute([$employeeId]);
    crm_json(['ok' => true, 'employee' => crm_public_employee($saved->fetch(), crm_can($user, 'finance.view') && crm_finance_unlocked())]);
}

if ($action === 'construction.photo' && $method === 'POST') {
    crm_require_origin();
    $user = crm_require_capability('clients.manage');
    crm_csrf();
    crm_schema_ensure_v30();
    $stageId = crm_text($_POST['stageId'] ?? '', 36);
    $statement = crm_db()->prepare('SELECT attachments_json FROM crm_construction_stages WHERE id = ?');
    $statement->execute([$stageId]);
    $row = $statement->fetch();
    if (!$row) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Этап строительства не найден.'], 422);
    $file = $_FILES['photo'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) crm_json(['ok' => false, 'code' => 'upload_failed', 'message' => 'Не удалось загрузить фотографию.'], 422);
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > 8 * 1024 * 1024) crm_json(['ok' => false, 'code' => 'payload_too_large', 'message' => 'Фотография должна быть не больше 8 МБ.'], 413);
    $temporary = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($temporary)) crm_json(['ok' => false, 'code' => 'upload_failed', 'message' => 'Файл не прошёл проверку загрузки.'], 422);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary); $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) crm_json(['ok' => false, 'code' => 'invalid_file_type', 'message' => 'Разрешены фотографии JPG, PNG и WebP.'], 422);
    $dimensions = @getimagesize($temporary);
    if (!$dimensions || $dimensions[0] < 32 || $dimensions[1] < 32 || $dimensions[0] > 12000 || $dimensions[1] > 12000) crm_json(['ok' => false, 'code' => 'invalid_image', 'message' => 'Файл повреждён или имеет неподходящий размер.'], 422);
    $attachments = json_decode((string)($row['attachments_json'] ?? '[]'), true); if (!is_array($attachments)) $attachments = [];
    if (count($attachments) >= 30) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'К одному этапу можно прикрепить не больше 30 фотографий.'], 422);
    $directory = dirname(__DIR__) . '/uploads/construction';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Cannot create construction upload directory');
    $key = 's-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($temporary, $directory . '/' . $key)) throw new RuntimeException('Cannot store construction photo');
    $attachments[] = ['key' => $key, 'name' => crm_text($file['name'] ?? 'Фото', 220), 'mime' => $mime, 'size' => $size, 'createdAt' => gmdate('c')];
    crm_db()->beginTransaction();
    crm_db()->prepare('UPDATE crm_construction_stages SET attachments_json = ? WHERE id = ?')->execute([json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $stageId]);
    crm_db()->exec("UPDATE crm_settings SET setting_value = CAST(setting_value AS UNSIGNED) + 1 WHERE setting_key = 'workspace_revision'");
    crm_db()->commit();
    crm_audit((int)$user['id'], 'construction.photo', 'construction_stage', $stageId, ['mime' => $mime, 'size' => $size]);
    crm_json(['ok' => true, 'attachment' => end($attachments)]);
}

if ($action === 'connectors.status' && $method === 'GET') {
    $user = crm_user();
    if (!crm_can($user, 'clients.view') && !crm_can($user, 'clients.manage')) crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Недостаточно прав для просмотра подключений.'], 403);
    crm_json(['ok' => true, 'connectors' => crm_connector_statuses()]);
}

if ($action === 'connectors.mail.configure' && $method === 'POST') {
    crm_require_origin(); $user = crm_require_capability('integrations.manage'); crm_csrf();
    $input = crm_input();
    $address = strtolower(crm_required_text($input['address'] ?? '', 'адрес почты', 190));
    $password = (string)($input['appPassword'] ?? '');
    if (!filter_var($address, FILTER_VALIDATE_EMAIL) || strlen($password) < 6 || strlen($password) > 200 || preg_match('/[\x00-\x1F\x7F]/', $password)) {
        crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте адрес ящика и пароль приложения.'], 422);
    }
    $mail = [
        'enabled' => true, 'from' => $address, 'sender_name' => 'ЭФТ',
        'smtp_host' => 'smtp.mail.ru', 'smtp_port' => 465, 'smtp_user' => $address, 'smtp_password' => $password,
        'imap_host' => 'imap.mail.ru', 'imap_port' => 993, 'imap_user' => $address, 'imap_password' => $password,
    ];
    crm_store_private_integration('mail', $mail);
    crm_audit((int)$user['id'], 'connector.mail.configure', 'integration', 'mail', ['address' => $address]);
    crm_json(['ok' => true, 'connectors' => crm_connector_statuses()]);
}

if ($action === 'connectors.telegram.configure' && $method === 'POST') {
    crm_require_origin(); $user = crm_require_capability('integrations.manage'); crm_csrf();
    $input = crm_input(); $token = trim((string)($input['botToken'] ?? ''));
    if (!preg_match('/^[0-9]{5,15}:[A-Za-z0-9_-]{30,100}$/', $token)) {
        crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте токен Telegram-бота.'], 422);
    }
    try {
        $reply = crm_http_json('https://api.telegram.org/bot' . $token . '/getMe', []);
        $username = (string)($reply['result']['username'] ?? '');
        if (empty($reply['ok']) || !preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) throw new RuntimeException('Bot identity unavailable');
    } catch (Throwable $error) {
        crm_json(['ok' => false, 'code' => 'bot_connection_failed', 'message' => 'Telegram не подтвердил токен бота. Проверьте его и повторите.'], 422);
    }
    $previous = crm_integration_config('telegram');
    $sameBot = (string)($previous['bot_token'] ?? '') !== '' && hash_equals((string)$previous['bot_token'], $token);
    $secret=$sameBot && preg_match('/^[a-f0-9]{48}$/',(string)($previous['webhook_secret']??'')) ? (string)$previous['webhook_secret'] : bin2hex(random_bytes(24));
    try { crm_http_json('https://api.telegram.org/bot'.$token.'/setWebhook', ['url'=>'https://crm.eftsip.ru/api/api.php?action=connectors.telegram.webhook','secret_token'=>$secret,'allowed_updates'=>['message']]); }
    catch (Throwable $error) { crm_json(['ok'=>false,'code'=>'webhook_failed','message'=>'Telegram подтвердил бота, но не удалось настроить приём сообщений. Повторите подключение.'],422); }
    crm_store_private_integration('telegram', [
        'enabled' => true, 'bot_token' => $token, 'bot_username' => $username,
        'site_chat_ids' => $sameBot && is_array($previous['site_chat_ids'] ?? null) ? $previous['site_chat_ids'] : [],
        'pending_links' => $sameBot && is_array($previous['pending_links'] ?? null) ? $previous['pending_links'] : [],
        'webhook_secret' => $secret,
    ]);
    crm_audit((int)$user['id'], 'connector.telegram.configure', 'integration', 'telegram', ['username' => $username]);
    crm_json(['ok' => true, 'username' => $username, 'connectors' => crm_connector_statuses()]);
}

if ($action === 'connectors.telegram.link' && $method === 'POST') {
    crm_require_origin(); $user=crm_require_capability('integrations.manage'); crm_csrf();
    $input=crm_input(); $siteId=crm_required_text($input['siteId']??'','объект клиента',36);
    $site=crm_db()->prepare('SELECT 1 FROM crm_sites WHERE id=?'); $site->execute([$siteId]);
    if (!$site->fetchColumn()) crm_json(['ok'=>false,'code'=>'validation_failed','message'=>'Объект не найден.'],422);
    $config=crm_integration_config('telegram'); $username=(string)($config['bot_username']??'');
    if (empty($config['enabled']) || !preg_match('/^[A-Za-z0-9_]{5,32}$/',$username)) crm_json(['ok'=>false,'code'=>'not_configured','message'=>'Сначала подключите Telegram-бота.'],422);
    $links=is_array($config['pending_links']??null)?$config['pending_links']:[];
    $links=array_filter($links,static fn($link):bool => is_array($link) && (int)($link['expires']??0)>time());
    $token=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
    $links[$token]=['siteId'=>$siteId,'expires'=>time()+86400]; $config['pending_links']=$links;
    crm_store_private_integration('telegram',$config);
    crm_audit((int)$user['id'],'connector.telegram.link','site',$siteId);
    crm_json(['ok'=>true,'url'=>'https://t.me/'.$username.'?start='.$token,'expiresAt'=>gmdate('c',time()+86400)]);
}

if ($action === 'connectors.max.configure' && $method === 'POST') {
    crm_require_origin(); $user = crm_require_capability('integrations.manage'); crm_csrf();
    $input = crm_input(); $token = trim((string)($input['botToken'] ?? ''));
    if (strlen($token) < 20 || strlen($token) > 512 || preg_match('/[\x00-\x20\x7F]/', $token)) {
        crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте токен MAX-бота.'], 422);
    }
    try {
        $bot = crm_http_get_json('https://platform-api2.max.ru/me', ['Authorization: ' . $token]);
        $username = (string)($bot['username'] ?? '');
        if (empty($bot['is_bot']) || $username === '' || !preg_match('/^[A-Za-z0-9_.-]{2,100}$/', $username)) throw new RuntimeException('Bot identity unavailable');
    } catch (Throwable $error) {
        crm_json(['ok' => false, 'code' => 'bot_connection_failed', 'message' => 'MAX не подтвердил токен бота. Проверьте его и повторите.'], 422);
    }
    $previous = crm_integration_config('max');
    $sameBot = (string)($previous['bot_token'] ?? '') !== '' && hash_equals((string)$previous['bot_token'], $token);
    $secret = $sameBot && preg_match('/^[a-f0-9]{48}$/', (string)($previous['webhook_secret'] ?? '')) ? (string)$previous['webhook_secret'] : bin2hex(random_bytes(24));
    try {
        $subscription = crm_http_json('https://platform-api2.max.ru/subscriptions', [
            'url' => 'https://crm.eftsip.ru/api/api.php?action=connectors.max.webhook',
            'update_types' => ['message_created', 'bot_started'], 'secret' => $secret,
        ], ['Authorization: ' . $token]);
        if (empty($subscription['success'])) throw new RuntimeException('Subscription rejected');
    } catch (Throwable $error) {
        crm_json(['ok' => false, 'code' => 'webhook_failed', 'message' => 'Бот подтверждён, но MAX не принял подписку на сообщения. Повторите подключение.'], 422);
    }
    crm_store_private_integration('max', [
        'enabled' => true, 'bot_token' => $token, 'bot_username' => $username,
        'site_chat_ids' => $sameBot && is_array($previous['site_chat_ids'] ?? null) ? $previous['site_chat_ids'] : [],
        'pending_links' => $sameBot && is_array($previous['pending_links'] ?? null) ? $previous['pending_links'] : [],
        'webhook_secret' => $secret,
    ]);
    crm_audit((int)$user['id'], 'connector.max.configure', 'integration', 'max', ['username' => $username]);
    crm_json(['ok' => true, 'username' => $username, 'connectors' => crm_connector_statuses()]);
}

if ($action === 'connectors.max.check' && $method === 'POST') {
    crm_require_origin(); $user = crm_require_capability('integrations.manage'); crm_csrf();
    $config = crm_integration_config('max');
    $token = (string)($config['bot_token'] ?? ''); $secret = (string)($config['webhook_secret'] ?? '');
    if (empty($config['enabled']) || $token === '' || $secret === '') crm_json(['ok' => false, 'code' => 'not_configured', 'message' => 'Сначала подключите MAX-бота.'], 422);
    $url = 'https://crm.eftsip.ru/api/api.php?action=connectors.max.webhook';
    try {
        $result = crm_http_get_json('https://platform-api2.max.ru/subscriptions', ['Authorization: ' . $token]);
        $active = false;
        foreach (is_array($result['subscriptions'] ?? null) ? $result['subscriptions'] : [] as $subscription) {
            $types = is_array($subscription['update_types'] ?? null) ? $subscription['update_types'] : [];
            if (($subscription['url'] ?? '') === $url && in_array('message_created', $types, true) && in_array('bot_started', $types, true)) { $active = true; break; }
        }
        $repaired = !$active;
        if ($repaired) {
            $response = crm_http_json('https://platform-api2.max.ru/subscriptions', [
                'url' => $url, 'update_types' => ['message_created', 'bot_started'], 'secret' => $secret,
            ], ['Authorization: ' . $token]);
            if (empty($response['success'])) throw new RuntimeException('MAX не подтвердил подписку.');
        }
    } catch (Throwable $error) {
        crm_json(['ok' => false, 'code' => 'max_check_failed', 'message' => 'Не удалось проверить MAX: ' . $error->getMessage()], 422);
    }
    crm_audit((int)$user['id'], 'connector.max.check', 'integration', 'max', ['repaired' => $repaired]);
    crm_json(['ok' => true, 'subscriptionActive' => true, 'repaired' => $repaired, 'linkedChats' => count((array)($config['site_chat_ids'] ?? []))]);
}

if ($action === 'connectors.max.link' && $method === 'POST') {
    crm_require_origin(); $user = crm_require_capability('integrations.manage'); crm_csrf();
    $input = crm_input(); $siteId = crm_required_text($input['siteId'] ?? '', 'объект клиента', 36);
    $site = crm_db()->prepare('SELECT 1 FROM crm_sites WHERE id=?'); $site->execute([$siteId]);
    if (!$site->fetchColumn()) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Объект не найден.'], 422);
    $config = crm_integration_config('max'); $username = (string)($config['bot_username'] ?? '');
    if (empty($config['enabled']) || $username === '') crm_json(['ok' => false, 'code' => 'not_configured', 'message' => 'Сначала подключите MAX-бота.'], 422);
    $links = is_array($config['pending_links'] ?? null) ? $config['pending_links'] : [];
    $links = array_filter($links, static fn($link): bool => is_array($link) && (int)($link['expires'] ?? 0) > time());
    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $links[$token] = ['siteId' => $siteId, 'expires' => time() + 86400]; $config['pending_links'] = $links;
    crm_store_private_integration('max', $config);
    crm_audit((int)$user['id'], 'connector.max.link', 'site', $siteId);
    crm_json(['ok' => true, 'url' => 'https://max.ru/' . rawurlencode($username) . '?start=' . $token, 'expiresAt' => gmdate('c', time() + 86400)]);
}

if ($action === 'communications.mail.sync' && $method === 'POST') {
    crm_require_origin(); $user = crm_require_capability('integrations.manage'); crm_csrf(); crm_schema_ensure_v30();
    $config = crm_integration_config('mail');
    if (empty($config['enabled'])) crm_json(['ok' => false, 'code' => 'not_configured', 'message' => 'Сначала подключите почтовый ящик на сервере.'], 422);
    try { $summary = crm_mail_sync($config); }
    catch (Throwable $error) { crm_json(['ok' => false, 'code' => 'mail_sync_failed', 'message' => $error->getMessage()], 422); }
    try { crm_push_dispatch(10); } catch (Throwable $error) { error_log('EFT CRM mail push failed: ' . $error->getMessage()); }
    crm_audit((int)$user['id'], 'communication.mail.sync', 'integration', 'mail', $summary);
    crm_json(['ok' => true, 'summary' => $summary, 'workspace' => crm_workspace_for_user($user)]);
}

if ($action === 'communications.assign' && $method === 'POST') {
    crm_require_origin(); $user = crm_require_capability('integrations.manage'); crm_csrf(); crm_schema_ensure_v30();
    $input = crm_input();
    $messageId = crm_required_text($input['messageId'] ?? '', 'сообщение', 36);
    $siteId = crm_required_text($input['siteId'] ?? '', 'объект клиента', 36);
    $site = crm_db()->prepare('SELECT 1 FROM crm_sites WHERE id=?'); $site->execute([$siteId]);
    if (!$site->fetchColumn()) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Объект клиента не найден.'], 422);
    $lookup = crm_db()->prepare("SELECT channel, attachments_json FROM crm_communications WHERE id=? AND site_id IS NULL AND channel IN ('email','max') AND is_ignored=0");
    $lookup->execute([$messageId]); $message = $lookup->fetch();
    if (!$message) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Сообщение уже привязано или не найдено.'], 422);
    if ($message['channel'] === 'max') {
        $entries = json_decode((string)$message['attachments_json'], true); $chatId = '';
        foreach (is_array($entries) ? $entries : [] as $entry) if (is_array($entry) && ($entry['kind'] ?? '') === 'max_chat') $chatId = (string)($entry['id'] ?? '');
        if (!preg_match('/^-?\d{1,20}$/', $chatId)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Не найден идентификатор диалога MAX.'], 422);
        $config = crm_integration_config('max'); $map = is_array($config['site_chat_ids'] ?? null) ? $config['site_chat_ids'] : [];
        $otherSite = array_search($chatId, $map, true);
        if ($otherSite !== false && $otherSite !== $siteId) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Этот MAX-чат уже привязан к другому объекту.'], 422);
        $map[$siteId] = $chatId; $config['site_chat_ids'] = $map;
        crm_store_private_integration('max', $config);
        $rows = crm_db()->query("SELECT id, attachments_json FROM crm_communications WHERE site_id IS NULL AND channel='max' AND is_ignored=0")->fetchAll();
        $statement = crm_db()->prepare("UPDATE crm_communications SET site_id=? WHERE id=? AND site_id IS NULL AND channel='max' AND is_ignored=0");
        foreach ($rows as $row) {
            $items = json_decode((string)$row['attachments_json'], true);
            foreach (is_array($items) ? $items : [] as $entry) {
                if (is_array($entry) && ($entry['kind'] ?? '') === 'max_chat' && (string)($entry['id'] ?? '') === $chatId) { $statement->execute([$siteId, $row['id']]); break; }
            }
        }
    } else {
        $statement = crm_db()->prepare("UPDATE crm_communications SET site_id=? WHERE id=? AND site_id IS NULL AND channel='email' AND is_ignored=0");
        $statement->execute([$siteId, $messageId]);
        if ($statement->rowCount() !== 1) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Письмо уже привязано или не найдено.'], 422);
    }
    crm_db()->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
    crm_audit((int)$user['id'], 'communication.assign', 'communication', $messageId, ['siteId' => $siteId]);
    crm_json(['ok' => true, 'workspace' => crm_workspace_for_user($user)]);
}

if ($action === 'communications.ignore' && $method === 'POST') {
    crm_require_origin(); $user = crm_require_capability('integrations.manage'); crm_csrf(); crm_schema_ensure_v30();
    $input = crm_input();
    $messageId = crm_required_text($input['messageId'] ?? '', 'письмо', 36);
    $ignored = !empty($input['ignored']) ? 1 : 0;
    $statement = crm_db()->prepare("UPDATE crm_communications SET is_ignored=?, is_read=1 WHERE id=? AND site_id IS NULL AND channel IN ('email','max')");
    $statement->execute([$ignored, $messageId]);
    if ($statement->rowCount() !== 1) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Сообщение не найдено или его статус уже изменён.'], 422);
    crm_db()->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
    crm_audit((int)$user['id'], $ignored ? 'communication.ignore' : 'communication.restore', 'communication', $messageId);
    crm_json(['ok' => true, 'workspace' => crm_workspace_for_user($user)]);
}

if ($action === 'communications.batch' && $method === 'POST') {
    crm_require_origin(); $user = crm_require_capability('integrations.manage'); crm_csrf(); crm_schema_ensure_v30();
    $input = crm_input();
    $ids = array_values(array_unique(array_filter($input['messageIds'] ?? [], 'is_string')));
    if (!$ids || count($ids) > 100 || array_filter($ids, static fn(string $id): bool => !preg_match('/^[a-f0-9-]{36}$/i', $id))) crm_json(['ok'=>false,'code'=>'validation_failed','message'=>'Выберите от 1 до 100 писем.'],422);
    $operation = (string)($input['operation'] ?? '');
    if (!in_array($operation, ['assign','ignore','restore'], true)) crm_json(['ok'=>false,'code'=>'validation_failed','message'=>'Действие не поддерживается.'],422);
    $siteId = '';
    if ($operation === 'assign') {
        $siteId = crm_required_text($input['siteId'] ?? '', 'объект клиента', 36);
        $site = crm_db()->prepare('SELECT 1 FROM crm_sites WHERE id=?'); $site->execute([$siteId]);
        if (!$site->fetchColumn()) crm_json(['ok'=>false,'code'=>'validation_failed','message'=>'Объект клиента не найден.'],422);
    }
    $db = crm_db(); $db->beginTransaction();
    try {
        $update = $operation === 'assign'
            ? $db->prepare("UPDATE crm_communications SET site_id=? WHERE id=? AND site_id IS NULL AND channel='email' AND is_ignored=0")
            : $db->prepare("UPDATE crm_communications SET is_ignored=?, is_read=1 WHERE id=? AND site_id IS NULL AND channel='email'");
        $changed = 0;
        foreach ($ids as $id) {
            $update->execute($operation === 'assign' ? [$siteId,$id] : [$operation === 'ignore' ? 1 : 0,$id]);
            $changed += $update->rowCount();
        }
        if ($changed) $db->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    crm_audit((int)$user['id'], 'communication.batch.'.$operation, 'communication', '', ['count'=>$changed,'siteId'=>$siteId]);
    crm_json(['ok'=>true,'changed'=>$changed,'workspace'=>crm_workspace_for_user($user)]);
}

if ($action === 'communications.workflow' && $method === 'POST') {
    crm_require_origin(); $user = crm_require_capability('clients.manage'); crm_csrf(); crm_schema_ensure_v30();
    $input = crm_input(); $id = crm_required_text($input['messageId'] ?? '', 'сообщение', 36);
    $status = (string)($input['status'] ?? '');
    if (!in_array($status, ['', 'reply','waiting','working','closed'], true)) crm_json(['ok'=>false,'code'=>'validation_failed','message'=>'Статус не поддерживается.'],422);
    $followUpAt = (string)($input['followUpAt'] ?? '');
    $parsedDate=$followUpAt!==''?DateTimeImmutable::createFromFormat('!Y-m-d',$followUpAt):null;
    if ($followUpAt !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$followUpAt) || !$parsedDate || $parsedDate->format('Y-m-d') !== $followUpAt)) crm_json(['ok'=>false,'code'=>'validation_failed','message'=>'Проверьте дату напоминания.'],422);
    $check=crm_db()->prepare('SELECT site_id FROM crm_communications WHERE id=?'); $check->execute([$id]);
    if (!$check->fetchColumn()) crm_json(['ok'=>false,'code'=>'validation_failed','message'=>'Сначала привяжите сообщение к клиенту.'],422);
    $db=crm_db(); $db->beginTransaction();
    try {
        $query=$db->prepare('SELECT site_id,attachments_json FROM crm_communications WHERE id=? FOR UPDATE'); $query->execute([$id]); $row=$query->fetch();
        if (!$row || !$row['site_id']) throw new RuntimeException('Сообщение больше не привязано к клиенту.');
        $attachments=json_decode((string)$row['attachments_json'],true); if (!is_array($attachments)) $attachments=[];
        $attachments=array_values(array_filter($attachments,static fn($entry):bool => !is_array($entry) || ($entry['kind']??'')!=='workflow'));
        if ($status !== '' || $followUpAt !== '') $attachments[]=['kind'=>'workflow','status'=>$status,'followUpAt'=>$followUpAt];
        $db->prepare('UPDATE crm_communications SET attachments_json=? WHERE id=?')->execute([json_encode($attachments,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id]);
        $db->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    crm_audit((int)$user['id'],'communication.workflow','communication',$id,['status'=>$status,'followUpAt'=>$followUpAt]);
    crm_json(['ok'=>true,'workspace'=>crm_workspace_for_user($user)]);
}

if ($action === 'communications.templates' && $method === 'GET') {
    $user=crm_user(); if (!crm_can($user,'clients.manage')) crm_json(['ok'=>false,'code'=>'forbidden','message'=>'Недостаточно прав.'],403);
    $statement=crm_db()->prepare('SELECT setting_value FROM crm_settings WHERE setting_key=?'); $statement->execute(['communication_templates']);
    $raw=$statement->fetchColumn();
    $defaults=[['id'=>'default-1','title'=>'Получили','text'=>'Спасибо, получили информацию.'],['id'=>'default-2','title'=>'Расчёт','text'=>'Подготовим расчёт и вернёмся с ответом.'],['id'=>'default-3','title'=>'Звонок','text'=>'Уточните, пожалуйста, удобное время для звонка.']];
    $templates=$raw===false?$defaults:json_decode((string)$raw,true);
    crm_json(['ok'=>true,'templates'=>is_array($templates)?$templates:$defaults]);
}

if ($action === 'communications.templates' && $method === 'POST') {
    crm_require_origin(); $user=crm_require_capability('integrations.manage'); crm_csrf();
    $input=crm_input(); $rows=$input['templates']??null;
    if (!is_array($rows) || count($rows)>40) crm_json(['ok'=>false,'code'=>'validation_failed','message'=>'Допускается не более 40 шаблонов.'],422);
    $templates=[]; foreach ($rows as $row) {
        if (!is_array($row)) crm_json(['ok'=>false,'code'=>'validation_failed','message'=>'Проверьте шаблон.'],422);
        $templates[]=['id'=>crm_required_text($row['id']??'','идентификатор',36),'title'=>crm_required_text($row['title']??'','название',100),'text'=>crm_required_text($row['text']??'','текст',4000)];
    }
    crm_db()->prepare("INSERT INTO crm_settings (setting_key,setting_value,updated_by) VALUES ('communication_templates',?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)")->execute([json_encode($templates,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$user['id']]);
    crm_audit((int)$user['id'],'communication.templates.update','settings','communication_templates',['count'=>count($templates)]);
    crm_json(['ok'=>true,'templates'=>$templates]);
}

if ($action === 'communications.rules' && $method === 'GET') {
    $user=crm_require_capability('integrations.manage');
    $statement=crm_db()->prepare('SELECT setting_value FROM crm_settings WHERE setting_key=?'); $statement->execute(['communication_mail_rules']);
    $rules=json_decode((string)($statement->fetchColumn() ?: '[]'),true);
    crm_json(['ok'=>true,'rules'=>is_array($rules)?$rules:[]]);
}

if ($action === 'communications.rules' && $method === 'POST') {
    crm_require_origin(); $user=crm_require_capability('integrations.manage'); crm_csrf();
    $input=crm_input(); $rows=$input['rules']??null;
    if (!is_array($rows) || count($rows)>30) crm_json(['ok'=>false,'code'=>'validation_failed','message'=>'Допускается не более 30 правил.'],422);
    $rules=[]; foreach ($rows as $row) {
        if (!is_array($row)) crm_json(['ok'=>false,'code'=>'validation_failed','message'=>'Проверьте правило.'],422);
        $field=(string)($row['field']??''); $operation=(string)($row['operation']??'');
        if (!in_array($field,['sender','subject'],true) || !in_array($operation,['ignore','assign'],true)) crm_json(['ok'=>false,'code'=>'validation_failed','message'=>'Проверьте тип правила.'],422);
        $siteId=crm_text($row['siteId']??'',36);
        if ($operation==='assign') { $check=crm_db()->prepare('SELECT 1 FROM crm_sites WHERE id=?'); $check->execute([$siteId]); if (!$check->fetchColumn()) crm_json(['ok'=>false,'code'=>'validation_failed','message'=>'Объект для правила не найден.'],422); }
        $rules[]=['id'=>crm_required_text($row['id']??'','идентификатор',36),'field'=>$field,'contains'=>crm_required_text($row['contains']??'','фрагмент',100),'operation'=>$operation,'siteId'=>$operation==='assign'?$siteId:''];
    }
    crm_db()->prepare("INSERT INTO crm_settings (setting_key,setting_value,updated_by) VALUES ('communication_mail_rules',?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)")->execute([json_encode($rules,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$user['id']]);
    crm_audit((int)$user['id'],'communication.rules.update','settings','communication_mail_rules',['count'=>count($rules)]);
    crm_json(['ok'=>true,'rules'=>$rules]);
}

if ($action === 'communications.send' && $method === 'POST') {
    crm_require_origin(); $user = crm_user(); crm_csrf(); crm_schema_ensure_v30();
    if (!crm_can($user, 'clients.manage')) crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Недостаточно прав для отправки сообщений.'], 403);
    $siteId = crm_required_text($_POST['siteId'] ?? '', 'объект', 36);
    $channel = in_array($_POST['channel'] ?? '', ['note','call','email','telegram','whatsapp','max'], true) ? (string)$_POST['channel'] : '';
    if ($channel === '') crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Выберите канал общения.'], 422);
    $body = crm_required_text($_POST['text'] ?? '', 'сообщение', 20000); $subject = crm_text($_POST['subject'] ?? '', 500); $replyTo = crm_text($_POST['replyTo'] ?? '', 36);
    $context = crm_db()->prepare('SELECT s.id, c.email, c.phone FROM crm_sites s JOIN crm_clients c ON c.id=s.client_id WHERE s.id=?'); $context->execute([$siteId]); $target = $context->fetch();
    if (!$target) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Клиент или объект не найден.'], 422);
    $recipientEmail = (string)$target['email'];
    if ($channel === 'email' && $replyTo !== '') {
        $previous = crm_db()->prepare("SELECT subject, attachments_json FROM crm_communications WHERE id=? AND site_id=? AND channel='email' AND direction='incoming'");
        $previous->execute([$replyTo, $siteId]); $received = $previous->fetch();
        if ($received) {
            $metadata = json_decode((string)$received['attachments_json'], true);
            foreach (is_array($metadata) ? $metadata : [] as $entry) {
                if (($entry['kind'] ?? '') === 'sender' && filter_var((string)($entry['email'] ?? ''), FILTER_VALIDATE_EMAIL)) $recipientEmail = (string)$entry['email'];
            }
            if ($subject === '' && (string)$received['subject'] !== '') $subject = 'Re: ' . preg_replace('/^(Re:\s*)+/i', '', (string)$received['subject']);
        }
    }
    $files = $_FILES['files'] ?? null; $items = []; $dispatchFiles = [];
    if (is_array($files) && is_array($files['name'] ?? null)) {
        if (count($files['name']) > 8) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'К сообщению можно приложить не больше 8 файлов.'], 422);
        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf','text/plain'=>'txt','application/zip'=>'zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx'];
        $total = 0; $directory = dirname(__DIR__) . '/uploads/communications';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Cannot create communication upload directory');
        foreach ($files['name'] as $index => $originalName) {
            if ((int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) crm_json(['ok' => false, 'code' => 'upload_failed', 'message' => 'Один из файлов не удалось загрузить.'], 422);
            $size = (int)($files['size'][$index] ?? 0); $total += $size; if ($size < 1 || $size > 10*1024*1024 || $total > 25*1024*1024) crm_json(['ok' => false, 'code' => 'payload_too_large', 'message' => 'Один файл — до 10 МБ, все вложения — до 25 МБ.'], 413);
            $temporary = (string)($files['tmp_name'][$index] ?? ''); if (!is_uploaded_file($temporary)) crm_json(['ok' => false, 'code' => 'upload_failed', 'message' => 'Файл не прошёл проверку загрузки.'], 422);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary); if (!isset($allowed[$mime])) crm_json(['ok' => false, 'code' => 'invalid_file_type', 'message' => 'Разрешены изображения, PDF, TXT, ZIP, DOCX и XLSX.'], 422);
            $key = 'm-' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime]; if (!move_uploaded_file($temporary, $directory . '/' . $key)) throw new RuntimeException('Cannot store communication attachment');
            $safeName=crm_text($originalName,220);$storedPath=$directory.'/'.$key;
            $items[] = ['key'=>$key,'name'=>$safeName,'mime'=>$mime,'size'=>$size,'url'=>'/api/api.php?action=communications.file&key='.rawurlencode($key)];
            $dispatchFiles[]=['path'=>$storedPath,'name'=>$safeName,'mime'=>$mime,'size'=>$size];
        }
    }
    $id = crm_uuid(); $direction = in_array($channel, ['note','call'], true) ? 'internal' : 'outgoing'; $type = $channel === 'call' ? 'call' : ($channel === 'email' ? 'email' : ($channel === 'note' ? 'note' : 'message'));
    $delivery = ['status' => $direction === 'internal' ? 'internal' : 'saved', 'externalKey' => '']; $deliveryError = '';
    try { $delivery = crm_dispatch_message($channel, $siteId, $recipientEmail, (string)$target['phone'], $subject, $body, $dispatchFiles); }
    catch (Throwable $error) { $delivery = ['status'=>'error','externalKey'=>'']; $deliveryError = mb_substr($error->getMessage(),0,1000); }
    $metadata = $items; if ($replyTo !== '') $metadata[] = ['kind'=>'reply','messageId'=>$replyTo];
    $statement = crm_db()->prepare('INSERT INTO crm_communications (id,site_id,task_id,activity_type,channel,direction,external_key,subject,body,is_read,attachments_json,delivery_status,delivery_error,occurred_at,author_id,author_employee_id) VALUES (?,?,NULL,?,?,?,?,?,?,1,?,?,?,?,?,?)');
    $statement->execute([$id,$siteId,$type,$channel,$direction,$delivery['externalKey'] ?: $id,$subject,$body,json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$delivery['status'],$deliveryError,date('Y-m-d H:i:s'),(int)$user['id'],$user['employeeId'] ?: null]);
    crm_db()->exec("UPDATE crm_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='workspace_revision'");
    crm_audit((int)$user['id'],'communication.send','communication',$id,['channel'=>$channel,'files'=>count($items),'status'=>$delivery['status']]);
    crm_json(['ok'=>true,'messageId'=>$id,'deliveryStatus'=>$delivery['status'],'deliveryError'=>$deliveryError,'workspace'=>crm_workspace_for_user($user)]);
}

if ($action === 'communications.file' && $method === 'GET') {
    $user = crm_user(); $key = (string)($_GET['key'] ?? '');
    if (!preg_match('/^m-[a-f0-9]{32}\.(jpg|png|webp|pdf|txt|zip|docx|xlsx)$/',$key)) { http_response_code(404); exit; }
    $statement = crm_db()->prepare('SELECT site_id,attachments_json FROM crm_communications WHERE attachments_json LIKE ? LIMIT 1'); $statement->execute(['%"key":"'.$key.'"%']);
    $row = $statement->fetch(); if (!$row) { http_response_code(404); exit; }
    if (!$row['site_id'] && !crm_can($user,'integrations.manage')) crm_json(['ok'=>false,'code'=>'forbidden','message'=>'Недостаточно прав.'],403);
    if ($row['site_id'] && !crm_can($user,'clients.manage') && !crm_can($user,'clients.view') && !crm_can($user,'integrations.manage')) crm_json(['ok'=>false,'code'=>'forbidden','message'=>'Недостаточно прав.'],403);
    $attachments = json_decode((string)$row['attachments_json'],true); $attachment = null; foreach (is_array($attachments)?$attachments:[] as $item) if (($item['key']??'')===$key) $attachment=$item;
    if (!$attachment) { http_response_code(404); exit; } $path=dirname(__DIR__).'/uploads/communications/'.$key; if (!is_file($path)) { http_response_code(404); exit; }
    header('Content-Type: '.(string)($attachment['mime']??'application/octet-stream')); header('Content-Length: '.filesize($path)); header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode((string)($attachment['name']??'file'))); header('X-Content-Type-Options: nosniff'); readfile($path); exit;
}

if ($action === 'attendance.list' && $method === 'GET') {
    $user = crm_user();
    $month = (string)($_GET['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте месяц.'], 422);
    $employeeId = crm_text($_GET['employeeId'] ?? '', 36);
    $canSeeAll = crm_can($user, 'attendance.manage') || crm_can($user, 'finance.view');
    if (!$canSeeAll) {
        if (!$user['employeeId']) crm_json(['ok' => true, 'attendance' => []]);
        $employeeId = (string)$user['employeeId'];
    }
    $sql = 'SELECT id, employee_id, work_date, kind, hours, note, updated_at FROM crm_attendance WHERE work_date >= ? AND work_date < DATE_ADD(?, INTERVAL 1 MONTH)';
    $params = [$month . '-01', $month . '-01'];
    if ($employeeId !== '') { $sql .= ' AND employee_id = ?'; $params[] = $employeeId; }
    $sql .= ' ORDER BY employee_id, work_date';
    $statement = crm_db()->prepare($sql);
    $statement->execute($params);
    $rows = array_map(static fn(array $row): array => ['id' => $row['id'], 'employeeId' => $row['employee_id'], 'date' => $row['work_date'], 'kind' => $row['kind'], 'hours' => (float)$row['hours'], 'note' => $row['note'], 'updatedAt' => $row['updated_at']], $statement->fetchAll());
    crm_json(['ok' => true, 'attendance' => $rows]);
}

if ($action === 'attendance.save' && in_array($method, ['POST', 'PUT'], true)) {
    crm_require_origin();
    $user = crm_user();
    crm_csrf();
    $input = crm_input();
    $employeeId = crm_required_text($input['employeeId'] ?? '', 'сотрудник', 36);
    $canManage = crm_can($user, 'attendance.manage');
    if (!$canManage && (!crm_can($user, 'attendance.self') || $user['employeeId'] !== $employeeId)) {
        crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Можно заполнять только свой табель.'], 403);
    }
    $employee = crm_db()->prepare('SELECT id, attendance_mode FROM crm_employees WHERE id = ? AND active = 1');
    $employee->execute([$employeeId]);
    $person = $employee->fetch();
    if (!$person) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Сотрудник не найден.'], 422);
    $kind = in_array($input['kind'] ?? '', ['work','off','leave','sick'], true) ? $input['kind'] : '';
    if ($kind === '') crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Выберите отметку.'], 422);
    $hours = 0.0;
    if ($kind === 'work') {
        $hours = $person['attendance_mode'] === 'days' ? 1.0 : (float)($input['hours'] ?? 0);
        if ($hours <= 0 || $hours > 24) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте количество часов.'], 422);
    }
    $date = crm_date($input['date'] ?? '');
    $find = crm_db()->prepare('SELECT id FROM crm_attendance WHERE employee_id = ? AND work_date = ?');
    $find->execute([$employeeId, $date]);
    $existingId = $find->fetchColumn();
    $id = $existingId ?: crm_uuid();
    if ($existingId) {
        $statement = crm_db()->prepare('UPDATE crm_attendance SET kind=?, hours=?, note=?, updated_by=? WHERE id=?');
        $statement->execute([$kind, $hours, crm_text($input['note'] ?? '', 1000), (int)$user['id'], $id]);
    } else {
        $statement = crm_db()->prepare('INSERT INTO crm_attendance (id, employee_id, work_date, kind, hours, note, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$id, $employeeId, $date, $kind, $hours, crm_text($input['note'] ?? '', 1000), (int)$user['id']]);
    }
    crm_audit((int)$user['id'], 'attendance.save', 'attendance', $id, ['employeeId' => $employeeId, 'date' => $date, 'kind' => $kind, 'hours' => $hours]);
    crm_json(['ok' => true, 'attendance' => ['id' => $id, 'employeeId' => $employeeId, 'date' => $date, 'kind' => $kind, 'hours' => $hours, 'note' => crm_text($input['note'] ?? '', 1000)]]);
}

if ($action === 'payroll' && $method === 'GET') {
    crm_require_capability('finance.view');
    crm_require_finance_unlocked();
    $month = (string)($_GET['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте месяц.'], 422);
    $employees = crm_db()->query('SELECT id, full_name, attendance_mode, pay_rate_cents, advance_amount_cents FROM crm_employees WHERE active = 1 ORDER BY full_name')->fetchAll();
    $attendance = crm_db()->prepare("SELECT employee_id, work_date, kind, hours FROM crm_attendance WHERE work_date >= ? AND work_date < DATE_ADD(?, INTERVAL 1 MONTH) ORDER BY work_date");
    $attendance->execute([$month . '-01', $month . '-01']);
    $byEmployee = [];
    foreach ($attendance->fetchAll() as $entry) $byEmployee[$entry['employee_id']][] = $entry;
    $rows = [];
    foreach ($employees as $employee) {
        $days = 0; $hours = 0.0; $daysTo25 = 0; $hoursTo25 = 0.0;
        foreach ($byEmployee[$employee['id']] ?? [] as $entry) {
            if ($entry['kind'] !== 'work' || (float)$entry['hours'] <= 0) continue;
            $days++; $hours += $employee['attendance_mode'] === 'days' ? 0 : (float)$entry['hours'];
            if ((int)substr($entry['work_date'], 8, 2) <= 25) { $daysTo25++; $hoursTo25 += $employee['attendance_mode'] === 'days' ? 0 : (float)$entry['hours']; }
        }
        $basis = $employee['attendance_mode'] === 'days' ? $days : $hours;
        $basisTo25 = $employee['attendance_mode'] === 'days' ? $daysTo25 : $hoursTo25;
        $accrued = (int)round($basis * (int)$employee['pay_rate_cents']);
        $fixedAdvance = (int)$employee['advance_amount_cents'];
        $advance = $fixedAdvance > 0 ? $fixedAdvance : (int)round($basisTo25 * (int)$employee['pay_rate_cents'] * 0.3);
        $rows[] = ['employeeId' => $employee['id'], 'employeeName' => $employee['full_name'], 'days' => $days, 'hours' => round($hours, 2), 'accrued' => $accrued / 100, 'advance' => $advance / 100, 'advanceKind' => $fixedAdvance > 0 ? 'fixed' : 'percent', 'settlement' => ($accrued - $advance) / 100];
    }
    $settlementMonth = (new DateTimeImmutable($month . '-01'))->modify('first day of next month')->format('Y-m-');
    crm_json(['ok' => true, 'month' => $month, 'advanceDate' => $month . '-25', 'settlementDate' => $settlementMonth . '10', 'rows' => $rows]);
}

crm_json(['ok' => false, 'code' => 'not_found', 'message' => 'Метод API не найден.'], 404);
