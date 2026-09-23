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
    crm_db()->prepare('UPDATE crm_users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
    crm_audit((int)$row['id'], 'auth.login', 'user', (string)$row['id']);
    crm_json(['ok' => true, 'user' => crm_session_user(), 'capabilities' => crm_capabilities((string)$row['role']), 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'session' && $method === 'GET') {
    crm_session_start();
    $user = crm_session_user();
    if (!$user) crm_json(['ok' => true, 'authenticated' => false]);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    crm_json(['ok' => true, 'authenticated' => true, 'user' => $user, 'capabilities' => crm_capabilities((string)$user['role']), 'csrf' => $_SESSION['csrf']]);
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
    $includeFinance = crm_can($user, 'finance.view');
    crm_json([
        'ok' => true,
        'user' => $user,
        'capabilities' => crm_capabilities((string)$user['role']),
        'employees' => array_map(static fn(array $row): array => crm_public_employee($row, $includeFinance), $rows),
    ]);
}

if ($action === 'employees.save' && in_array($method, ['POST', 'PUT'], true)) {
    crm_require_origin();
    $user = crm_require_capability('employees.manage');
    crm_csrf();
    $input = crm_input();
    $id = crm_text($input['id'] ?? '', 36) ?: crm_uuid();
    $name = crm_required_text($input['name'] ?? '', 'ФИО сотрудника', 200);
    $attendanceMode = in_array($input['attendanceMode'] ?? '', ['hours', 'days'], true) ? $input['attendanceMode'] : 'hours';
    $avatarKey = preg_match('/^employee-(0[1-9]|1[0-5])\.jpg$/', (string)($input['avatarKey'] ?? '')) ? (string)$input['avatarKey'] : 'employee-01.jpg';
    $active = !array_key_exists('active', $input) || (bool)$input['active'];
    $existing = crm_db()->prepare('SELECT id, pay_rate_cents, advance_amount_cents FROM crm_employees WHERE id = ?');
    $existing->execute([$id]);
    $previous = $existing->fetch();
    $payRate = $previous ? (int)$previous['pay_rate_cents'] : 0;
    $advance = $previous ? (int)$previous['advance_amount_cents'] : 0;
    if ((isset($input['payRate']) || isset($input['advanceAmount'])) && !crm_can($user, 'finance.manage')) {
        crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Изменять ставки может только финансовая роль.'], 403);
    }
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
    crm_json(['ok' => true, 'employee' => crm_public_employee($saved->fetch(), crm_can($user, 'finance.view'))]);
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
