<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

crm_headers();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string)($_GET['action'] ?? 'health');

if ($action === 'health' && $method === 'GET') {
    crm_db()->query('SELECT 1');
    crm_json(['ok' => true, 'service' => 'eft-crm-api', 'time' => gmdate('c')]);
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
    crm_json(array_merge(['ok' => true], $workspace));
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

if ($action === 'communications.send' && $method === 'POST') {
    crm_require_origin(); $user = crm_user(); crm_csrf(); crm_schema_ensure_v30();
    if (!crm_can($user, 'clients.manage')) crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Недостаточно прав для отправки сообщений.'], 403);
    $siteId = crm_required_text($_POST['siteId'] ?? '', 'объект', 36);
    $channel = in_array($_POST['channel'] ?? '', ['note','call','email','telegram','whatsapp','max'], true) ? (string)$_POST['channel'] : '';
    if ($channel === '') crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Выберите канал общения.'], 422);
    $body = crm_required_text($_POST['text'] ?? '', 'сообщение', 20000); $subject = crm_text($_POST['subject'] ?? '', 500); $replyTo = crm_text($_POST['replyTo'] ?? '', 36);
    $context = crm_db()->prepare('SELECT s.id, c.email, c.phone FROM crm_sites s JOIN crm_clients c ON c.id=s.client_id WHERE s.id=?'); $context->execute([$siteId]); $target = $context->fetch();
    if (!$target) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Клиент или объект не найден.'], 422);
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
    try { $delivery = crm_dispatch_message($channel, $siteId, (string)$target['email'], (string)$target['phone'], $subject, $body, $dispatchFiles); }
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
    $statement = crm_db()->prepare('SELECT attachments_json FROM crm_communications WHERE attachments_json LIKE ? LIMIT 1'); $statement->execute(['%"key":"'.$key.'"%']);
    $row = $statement->fetch(); if (!$row) { http_response_code(404); exit; }
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
