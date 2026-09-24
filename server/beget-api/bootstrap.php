<?php
declare(strict_types=1);

set_exception_handler(static function (Throwable $error): void {
    error_log('EFT CRM API error: ' . $error->getMessage());
    crm_json(['ok' => false, 'code' => 'server_error', 'message' => 'Внутренняя ошибка сервера.'], 500);
});

function crm_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function crm_config(): array {
    static $config = null;
    if (is_array($config)) return $config;
    $path = __DIR__ . '/config.local.php';
    if (!is_file($path)) crm_json(['ok' => false, 'code' => 'not_configured', 'message' => 'Сервер CRM ещё не настроен.'], 503);
    $config = require $path;
    if (!is_array($config) || strlen((string)($config['app_secret'] ?? '')) < 32) {
        crm_json(['ok' => false, 'code' => 'bad_config', 'message' => 'Ошибка конфигурации CRM.'], 503);
    }
    return $config;
}

function crm_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $config = crm_config();
    try {
        $pdo = new PDO(
            'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
            $config['db_user'],
            $config['db_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    } catch (Throwable $error) {
        error_log('EFT CRM database error: ' . $error->getMessage());
        crm_json(['ok' => false, 'code' => 'database_unavailable', 'message' => 'База CRM временно недоступна.'], 503);
    }
    return $pdo;
}

function crm_headers(): void {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && in_array($origin, crm_config()['allowed_origins'] ?? [], true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
}

function crm_require_origin(): void {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && !in_array($origin, crm_config()['allowed_origins'] ?? [], true)) {
        crm_json(['ok' => false, 'code' => 'origin_forbidden', 'message' => 'Источник запроса не разрешён.'], 403);
    }
}

function crm_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $config = crm_config();
    session_name('EFTCRMSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => (string)($config['cookie_domain'] ?? ''),
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function crm_input(int $maxBytes = 1048576): array {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > $maxBytes) crm_json(['ok' => false, 'code' => 'payload_too_large', 'message' => 'Слишком большой запрос.'], 413);
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) crm_json(['ok' => false, 'code' => 'invalid_json', 'message' => 'Некорректный JSON.'], 400);
    return $data;
}

function crm_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function crm_text(mixed $value, int $max = 2000): string {
    return mb_substr(trim((string)$value), 0, $max);
}

function crm_required_text(mixed $value, string $label, int $max = 2000): string {
    $text = crm_text($value, $max);
    if ($text === '') crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Заполните: ' . $label], 422);
    return $text;
}

function crm_avatar_key(mixed $value): string {
    $key = (string)$value;
    return preg_match('/^(employee-(0[1-9]|1[0-5])\.jpg|e-[a-f0-9]{24}\.(jpg|png|webp))$/', $key) ? $key : 'employee-01.jpg';
}

function crm_date(mixed $value): string {
    $date = (string)$value;
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Укажите корректную дату.'], 422);
    return $date;
}

function crm_session_tag(array $row): string {
    return hash_hmac('sha256', $row['password_hash'] . '|' . $row['role'] . '|' . $row['active'], (string)crm_config()['app_secret']);
}

function crm_session_user(): ?array {
    crm_session_start();
    if (empty($_SESSION['user_id']) || empty($_SESSION['auth_tag'])) return null;
    $statement = crm_db()->prepare('SELECT id, username, display_name, password_hash, role, employee_id, active FROM crm_users WHERE id = ? LIMIT 1');
    $statement->execute([(int)$_SESSION['user_id']]);
    $row = $statement->fetch();
    if (!$row || !(int)$row['active'] || !hash_equals(crm_session_tag($row), (string)$_SESSION['auth_tag'])) {
        $_SESSION = [];
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'username' => $row['username'],
        'displayName' => $row['display_name'],
        'role' => $row['role'],
        'employeeId' => $row['employee_id'] ?: null,
    ];
}

function crm_user(): array {
    $user = crm_session_user();
    if (!$user) crm_json(['ok' => false, 'code' => 'authentication_required', 'message' => 'Войдите в CRM.'], 401);
    return $user;
}

function crm_capabilities(string $role): array {
    $matrix = [
        'owner' => ['*'],
        'admin' => ['employees.manage','crews.manage','clients.manage','tasks.manage','attendance.manage','integrations.manage','audit.view'],
        'finance' => ['employees.view','attendance.view','finance.view','finance.manage'],
        'manager' => ['employees.view','clients.manage','tasks.manage','attendance.self'],
        'production' => ['employees.view','crews.view','tasks.manage','attendance.manage'],
        'procurement' => ['employees.view','clients.view','tasks.view','procurement.manage'],
        'foreman' => ['employees.view','crews.view','tasks.crew','attendance.self'],
        'employee' => ['tasks.self','attendance.self'],
        'viewer' => ['clients.view','tasks.view'],
    ];
    return $matrix[$role] ?? [];
}

function crm_settings_ensure(): void {
    static $ready = false;
    if ($ready) return;
    crm_db()->exec("CREATE TABLE IF NOT EXISTS crm_settings (setting_key VARCHAR(120) NOT NULL, setting_value VARCHAR(255) NOT NULL, updated_by BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (setting_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $exists = crm_db()->prepare('SELECT setting_key FROM crm_settings WHERE setting_key = ?');
    $exists->execute(['attendance_finance_pin_hash']);
    if (!$exists->fetchColumn()) crm_db()->prepare('INSERT INTO crm_settings (setting_key, setting_value) VALUES (?, ?)')->execute(['attendance_finance_pin_hash', password_hash('911', PASSWORD_DEFAULT)]);
    $ready = true;
}

function crm_column_exists(string $table, string $column): bool {
    $statement = crm_db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $statement->execute([$table, $column]);
    return (int)$statement->fetchColumn() > 0;
}

function crm_schema_ensure_v29(): void {
    static $ready = false;
    if ($ready) return;
    crm_settings_ensure();
    $db = crm_db();
    $db->exec("CREATE TABLE IF NOT EXISTS crm_orders (
      id CHAR(36) NOT NULL, public_number VARCHAR(32) NOT NULL, site_id CHAR(36) NOT NULL, lead_id CHAR(36) NULL,
      scope TEXT NOT NULL, reference_text VARCHAR(1000) NOT NULL DEFAULT '', approved_by_employee_id CHAR(36) NULL,
      approved_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id), UNIQUE KEY uq_crm_orders_number (public_number), KEY idx_crm_orders_site (site_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $columns = [
        'crm_leads' => [
            'owner_employee_id' => "CHAR(36) NULL AFTER owner_id",
            'source' => "VARCHAR(120) NOT NULL DEFAULT '' AFTER next_action_at",
            'notes' => "TEXT NULL AFTER source",
        ],
        'crm_tasks' => [
            'order_id' => "CHAR(36) NULL AFTER id",
            'quantity' => "DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER due_at",
            'completed_quantity' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER quantity",
            'unit_name' => "VARCHAR(80) NOT NULL DEFAULT 'задача' AFTER completed_quantity",
            'block_reason' => "TEXT NULL AFTER unit_name",
            'checklist_json' => "JSON NULL AFTER block_reason",
            'original_due_at' => "DATETIME NULL AFTER checklist_json",
            'reschedule_history_json' => "JSON NULL AFTER original_due_at",
        ],
        'crm_communications' => [
            'task_id' => "CHAR(36) NULL AFTER site_id",
            'activity_type' => "VARCHAR(32) NOT NULL DEFAULT 'note' AFTER task_id",
            'author_employee_id' => "CHAR(36) NULL AFTER author_id",
        ],
    ];
    foreach ($columns as $table => $definitions) foreach ($definitions as $column => $definition) {
        if (!crm_column_exists($table, $column)) $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
    foreach (['workspace_revision' => '0', 'workspace_initialized' => '0'] as $key => $value) {
        $statement = $db->prepare('INSERT IGNORE INTO crm_settings (setting_key, setting_value) VALUES (?, ?)');
        $statement->execute([$key, $value]);
    }
    $ready = true;
}

function crm_db_datetime(?string $value, bool $withSeconds = false): string {
    if (!$value) return '';
    return str_replace(' ', 'T', substr($value, 0, $withSeconds ? 19 : 16));
}

function crm_public_workspace(): array {
    crm_schema_ensure_v29();
    $db = crm_db();
    $clients = array_map(static fn(array $row): array => [
        'id' => $row['id'], 'name' => $row['display_name'], 'phone' => $row['phone'], 'email' => $row['email'],
    ], $db->query('SELECT id, display_name, phone, email FROM crm_clients ORDER BY created_at')->fetchAll());
    $sites = array_map(static fn(array $row): array => [
        'id' => $row['id'], 'clientId' => $row['client_id'], 'name' => $row['name'], 'address' => $row['address'],
    ], $db->query('SELECT id, client_id, name, address FROM crm_sites ORDER BY created_at')->fetchAll());
    $leads = array_map(static fn(array $row): array => [
        'id' => $row['id'], 'siteId' => $row['site_id'], 'status' => $row['status'] === 'in_progress' ? 'working' : $row['status'],
        'ownerId' => $row['owner_employee_id'] ?: '', 'nextAction' => $row['next_action'], 'dueAt' => crm_db_datetime($row['next_action_at']),
        'source' => $row['source'] ?: 'Вручную', 'notes' => $row['notes'] ?: '', 'createdAt' => crm_db_datetime($row['created_at'], true),
    ], $db->query('SELECT id, site_id, status, owner_employee_id, next_action, next_action_at, source, notes, created_at FROM crm_leads ORDER BY created_at DESC')->fetchAll());
    $orders = array_map(static fn(array $row): array => [
        'id' => $row['id'], 'number' => $row['public_number'], 'siteId' => $row['site_id'], 'leadId' => $row['lead_id'] ?: '',
        'scope' => $row['scope'], 'reference' => $row['reference_text'], 'approvedBy' => $row['approved_by_employee_id'] ?: '',
        'approvedAt' => crm_db_datetime($row['approved_at'], true), 'createdAt' => crm_db_datetime($row['created_at'], true),
    ], $db->query('SELECT * FROM crm_orders ORDER BY created_at DESC')->fetchAll());
    $tasks = array_map(static function (array $row): array {
        $checklist = json_decode((string)($row['checklist_json'] ?? '[]'), true);
        $history = json_decode((string)($row['reschedule_history_json'] ?? '[]'), true);
        return [
            'id' => $row['id'], 'orderId' => $row['order_id'] ?: '', 'title' => $row['title'], 'description' => $row['description'],
            'assigneeId' => $row['assignee_id'] ?: '', 'status' => $row['status'], 'priority' => $row['priority'], 'dueAt' => crm_db_datetime($row['due_at']),
            'quantity' => (float)$row['quantity'], 'completedQty' => (float)$row['completed_quantity'], 'unit' => $row['unit_name'],
            'blockReason' => $row['block_reason'] ?: '', 'checklist' => is_array($checklist) ? $checklist : [],
            'createdAt' => crm_db_datetime($row['created_at'], true), 'originalDueAt' => crm_db_datetime($row['original_due_at']),
            'rescheduleHistory' => is_array($history) ? $history : [],
        ];
    }, $db->query('SELECT * FROM crm_tasks ORDER BY created_at DESC')->fetchAll());
    $activities = array_map(static fn(array $row): array => [
        'id' => $row['id'], 'siteId' => $row['site_id'] ?: '', 'taskId' => $row['task_id'] ?: '', 'type' => $row['activity_type'],
        'text' => $row['body'], 'authorId' => $row['author_employee_id'] ?: '', 'createdAt' => crm_db_datetime($row['occurred_at'], true),
    ], $db->query('SELECT id, site_id, task_id, activity_type, body, author_employee_id, occurred_at FROM crm_communications ORDER BY occurred_at DESC')->fetchAll());
    $settings = $db->query("SELECT setting_key, setting_value FROM crm_settings WHERE setting_key IN ('workspace_revision','workspace_initialized')")->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'clients' => $clients, 'sites' => $sites, 'leads' => $leads, 'orders' => $orders, 'tasks' => $tasks, 'activities' => $activities,
        'workspaceRevision' => (int)($settings['workspace_revision'] ?? 0), 'workspaceInitialized' => ($settings['workspace_initialized'] ?? '0') === '1',
    ];
}

function crm_workspace_for_user(array $user): array {
    $workspace = crm_public_workspace();
    if (crm_can($user, 'clients.view') || crm_can($user, 'clients.manage')) return $workspace;
    $tasks = $workspace['tasks'];
    if (!crm_can($user, 'tasks.manage') && !crm_can($user, 'tasks.view')) {
        $employeeId = (string)($user['employeeId'] ?? '');
        $tasks = array_values(array_filter($tasks, static fn(array $task): bool => $employeeId !== '' && $task['assigneeId'] === $employeeId));
    }
    $taskIds = array_fill_keys(array_column($tasks, 'id'), true);
    $orderIds = array_fill_keys(array_filter(array_column($tasks, 'orderId')), true);
    $orders = array_values(array_filter($workspace['orders'], static fn(array $order): bool => isset($orderIds[$order['id']])));
    $siteIds = array_fill_keys(array_filter(array_column($orders, 'siteId')), true);
    $sites = array_values(array_filter($workspace['sites'], static fn(array $site): bool => isset($siteIds[$site['id']])));
    $clientIds = array_fill_keys(array_column($sites, 'clientId'), true);
    $clients = array_values(array_map(static fn(array $client): array => array_merge($client, ['phone' => '', 'email' => '']), array_filter($workspace['clients'], static fn(array $client): bool => isset($clientIds[$client['id']]))));
    $activities = array_values(array_filter($workspace['activities'], static fn(array $activity): bool => $activity['taskId'] !== '' && isset($taskIds[$activity['taskId']])));
    return array_merge($workspace, ['clients' => $clients, 'sites' => $sites, 'leads' => [], 'orders' => $orders, 'tasks' => $tasks, 'activities' => $activities]);
}

function crm_sql_datetime(mixed $value): ?string {
    $text = trim((string)$value);
    if ($text === '') return null;
    try { $date = new DateTimeImmutable($text); }
    catch (Throwable) { crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте дату и время.'], 422); }
    return $date->format('Y-m-d H:i:s');
}

function crm_workspace_rows(mixed $value, string $label, int $limit = 50000): array {
    if (!is_array($value) || count($value) > $limit) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Некорректный раздел: ' . $label], 422);
    foreach ($value as $row) if (!is_array($row)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Некорректная запись: ' . $label], 422);
    return $value;
}

function crm_workspace_save(array $workspace, array $user, int $baseRevision, bool $initialize): array {
    crm_schema_ensure_v29();
    $db = crm_db();
    $clients = crm_workspace_rows($workspace['clients'] ?? null, 'клиенты', 20000);
    $sites = crm_workspace_rows($workspace['sites'] ?? null, 'объекты', 30000);
    $leads = crm_workspace_rows($workspace['leads'] ?? null, 'заявки', 30000);
    $orders = crm_workspace_rows($workspace['orders'] ?? null, 'заказы', 30000);
    $tasks = crm_workspace_rows($workspace['tasks'] ?? null, 'задачи', 50000);
    $activities = crm_workspace_rows($workspace['activities'] ?? null, 'история', 100000);
    $db->beginTransaction();
    try {
        $lock = $db->prepare("SELECT setting_key, setting_value FROM crm_settings WHERE setting_key IN ('workspace_revision','workspace_initialized') FOR UPDATE");
        $lock->execute();
        $settings = $lock->fetchAll(PDO::FETCH_KEY_PAIR);
        $revision = (int)($settings['workspace_revision'] ?? 0);
        $initialized = ($settings['workspace_initialized'] ?? '0') === '1';
        if ($revision !== $baseRevision) {
            $db->rollBack();
            crm_json(['ok' => false, 'code' => 'workspace_conflict', 'message' => 'Данные уже изменил другой сотрудник. Рабочее пространство обновлено; повторите действие.'], 409);
        }
        if ($initialize && !$initialized && (int)$db->query('SELECT COUNT(*) FROM crm_employees')->fetchColumn() === 0) {
            $insertEmployee = $db->prepare('INSERT INTO crm_employees (id, full_name, role_name, department, phone, email, avatar_key, attendance_mode, active, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach (crm_workspace_rows($workspace['employees'] ?? [], 'сотрудники', 5000) as $index => $person) {
                $insertEmployee->execute([
                    crm_required_text($person['id'] ?? '', 'идентификатор сотрудника', 36), crm_required_text($person['name'] ?? '', 'ФИО сотрудника', 200),
                    crm_text($person['role'] ?? '', 120), crm_text($person['department'] ?? '', 120), crm_text($person['phone'] ?? '', 60), crm_text($person['email'] ?? '', 190),
                    crm_avatar_key(basename((string)($person['avatarKey'] ?? $person['avatar'] ?? 'employee-' . str_pad((string)($index % 15 + 1), 2, '0', STR_PAD_LEFT) . '.jpg'))),
                    in_array($person['attendanceMode'] ?? '', ['hours','days'], true) ? $person['attendanceMode'] : 'hours', !array_key_exists('active', $person) || (bool)$person['active'] ? 1 : 0,
                    crm_text($person['notes'] ?? '', 10000),
                ]);
            }
        }
        $employeeIds = array_fill_keys($db->query('SELECT id FROM crm_employees')->fetchAll(PDO::FETCH_COLUMN), true);
        $db->exec('DELETE FROM crm_communications');
        $db->exec('DELETE FROM crm_tasks');
        $db->exec('DELETE FROM crm_orders');
        $db->exec('DELETE FROM crm_leads');
        $db->exec('DELETE FROM crm_sites');
        $db->exec('DELETE FROM crm_clients');
        $insertClient = $db->prepare('INSERT INTO crm_clients (id, display_name, phone, email, source, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($clients as $row) $insertClient->execute([
            crm_required_text($row['id'] ?? '', 'идентификатор клиента', 36), crm_required_text($row['name'] ?? '', 'клиент', 200), crm_text($row['phone'] ?? '', 60), crm_text($row['email'] ?? '', 190), '', '', (int)$user['id'], crm_sql_datetime($row['createdAt'] ?? '') ?? date('Y-m-d H:i:s'),
        ]);
        $insertSite = $db->prepare('INSERT INTO crm_sites (id, client_id, name, address, created_at) VALUES (?, ?, ?, ?, ?)');
        foreach ($sites as $row) $insertSite->execute([
            crm_required_text($row['id'] ?? '', 'идентификатор объекта', 36), crm_required_text($row['clientId'] ?? '', 'клиент объекта', 36), crm_required_text($row['name'] ?? '', 'объект', 220), crm_text($row['address'] ?? '', 500), crm_sql_datetime($row['createdAt'] ?? '') ?? date('Y-m-d H:i:s'),
        ]);
        $insertLead = $db->prepare('INSERT INTO crm_leads (id, site_id, public_number, status, owner_id, owner_employee_id, next_action, next_action_at, source, notes, created_at) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)');
        foreach ($leads as $index => $row) {
            $status = (string)($row['status'] ?? 'new'); if ($status === 'working') $status = 'in_progress';
            if (!in_array($status, ['new','in_progress','calculation','offer','approval','contract','won','lost'], true)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Некорректный этап заявки.'], 422);
            $owner = crm_text($row['ownerId'] ?? '', 36); if (!isset($employeeIds[$owner])) $owner = '';
            $id = crm_required_text($row['id'] ?? '', 'идентификатор заявки', 36);
            $insertLead->execute([$id, crm_required_text($row['siteId'] ?? '', 'объект заявки', 36), 'L-' . substr(hash('sha256', $id), 0, 16), $status, $owner ?: null, crm_text($row['nextAction'] ?? '', 250), crm_sql_datetime($row['dueAt'] ?? ''), crm_text($row['source'] ?? '', 120), crm_text($row['notes'] ?? '', 10000), crm_sql_datetime($row['createdAt'] ?? '') ?? date('Y-m-d H:i:s')]);
        }
        $insertOrder = $db->prepare('INSERT INTO crm_orders (id, public_number, site_id, lead_id, scope, reference_text, approved_by_employee_id, approved_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($orders as $row) {
            $approvedBy = crm_text($row['approvedBy'] ?? '', 36); if (!isset($employeeIds[$approvedBy])) $approvedBy = '';
            $insertOrder->execute([crm_required_text($row['id'] ?? '', 'идентификатор заказа', 36), crm_required_text($row['number'] ?? '', 'номер заказа', 32), crm_required_text($row['siteId'] ?? '', 'объект заказа', 36), crm_text($row['leadId'] ?? '', 36) ?: null, crm_required_text($row['scope'] ?? '', 'комплектация заказа', 10000), crm_text($row['reference'] ?? '', 1000), $approvedBy ?: null, crm_sql_datetime($row['approvedAt'] ?? ''), crm_sql_datetime($row['createdAt'] ?? '') ?? date('Y-m-d H:i:s')]);
        }
        $orderSites = $db->query('SELECT id, site_id FROM crm_orders')->fetchAll(PDO::FETCH_KEY_PAIR);
        $insertTask = $db->prepare('INSERT INTO crm_tasks (id, order_id, title, description, site_id, assignee_id, crew_id, status, priority, due_at, quantity, completed_quantity, unit_name, block_reason, checklist_json, original_due_at, reschedule_history_json, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($tasks as $row) {
            $orderId = crm_text($row['orderId'] ?? '', 36); $assignee = crm_text($row['assigneeId'] ?? '', 36); if (!isset($employeeIds[$assignee])) $assignee = '';
            $status = in_array($row['status'] ?? '', ['planned','doing','review','blocked','done'], true) ? $row['status'] : 'planned';
            $priority = ($row['priority'] ?? '') === 'high' ? 'high' : 'normal';
            $quantity = (float)($row['quantity'] ?? 1); $completed = (float)($row['completedQty'] ?? 0);
            if ($quantity <= 0 || $completed < 0 || $completed > $quantity) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте объём задания.'], 422);
            $insertTask->execute([crm_required_text($row['id'] ?? '', 'идентификатор задачи', 36), $orderId ?: null, crm_required_text($row['title'] ?? '', 'название задачи', 250), crm_text($row['description'] ?? '', 20000), $orderSites[$orderId] ?? null, $assignee ?: null, $status, $priority, crm_sql_datetime($row['dueAt'] ?? ''), $quantity, $completed, crm_required_text($row['unit'] ?? 'задача', 'единица', 80), crm_text($row['blockReason'] ?? '', 10000), json_encode(is_array($row['checklist'] ?? null) ? $row['checklist'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), crm_sql_datetime($row['originalDueAt'] ?? ''), json_encode(is_array($row['rescheduleHistory'] ?? null) ? $row['rescheduleHistory'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$user['id'], crm_sql_datetime($row['createdAt'] ?? '') ?? date('Y-m-d H:i:s')]);
        }
        $insertActivity = $db->prepare('INSERT INTO crm_communications (id, site_id, task_id, activity_type, channel, direction, external_key, subject, body, occurred_at, author_id, author_employee_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($activities as $row) {
            $type = in_array($row['type'] ?? '', ['note','call','meeting','email','message','system'], true) ? $row['type'] : 'note';
            $channel = in_array($type, ['note','call','email','system'], true) ? $type : 'note';
            $author = crm_text($row['authorId'] ?? '', 36); if (!isset($employeeIds[$author])) $author = $user['employeeId'] && isset($employeeIds[$user['employeeId']]) ? (string)$user['employeeId'] : '';
            $insertActivity->execute([crm_required_text($row['id'] ?? '', 'идентификатор записи', 36), crm_text($row['siteId'] ?? '', 36) ?: null, crm_text($row['taskId'] ?? '', 36) ?: null, $type, $channel, 'internal', '', '', crm_required_text($row['text'] ?? '', 'текст записи', 20000), crm_sql_datetime($row['createdAt'] ?? '') ?? date('Y-m-d H:i:s'), (int)$user['id'], $author ?: null]);
        }
        $nextRevision = $revision + 1;
        $db->prepare("UPDATE crm_settings SET setting_value = ? WHERE setting_key = 'workspace_revision'")->execute([(string)$nextRevision]);
        $db->prepare("UPDATE crm_settings SET setting_value = '1' WHERE setting_key = 'workspace_initialized'")->execute();
        $db->commit();
        crm_audit((int)$user['id'], $initialize && !$initialized ? 'workspace.initialize' : 'workspace.save', 'workspace', (string)$nextRevision, ['clients' => count($clients), 'tasks' => count($tasks)]);
        return crm_public_workspace();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function crm_finance_unlocked(): bool {
    crm_session_start();
    return !empty($_SESSION['finance_unlocked']);
}

function crm_require_finance_unlocked(): void {
    if (!crm_finance_unlocked()) crm_json(['ok' => false, 'code' => 'finance_locked', 'message' => 'Сначала откройте финансовую часть паролем.'], 403);
}

function crm_public_user(array $row): array {
    return ['id' => (int)$row['id'], 'username' => $row['username'], 'displayName' => $row['display_name'], 'role' => $row['role'], 'employeeId' => $row['employee_id'] ?: '', 'active' => (bool)$row['active'], 'lastLoginAt' => $row['last_login_at']];
}

function crm_can(array $user, string $capability): bool {
    $capabilities = crm_capabilities((string)$user['role']);
    return in_array('*', $capabilities, true) || in_array($capability, $capabilities, true);
}

function crm_require_capability(string $capability): array {
    $user = crm_user();
    if (!crm_can($user, $capability)) crm_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Недостаточно прав.'], 403);
    return $user;
}

function crm_csrf(): void {
    crm_session_start();
    $expected = (string)($_SESSION['csrf'] ?? '');
    $received = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($expected === '' || !hash_equals($expected, $received)) crm_json(['ok' => false, 'code' => 'csrf_failed', 'message' => 'Сессия изменилась. Повторите действие.'], 419);
}

function crm_audit(?int $userId, string $action, string $entityType, string $entityId = '', array $details = []): void {
    $statement = crm_db()->prepare('INSERT INTO crm_audit_log (user_id, action_name, entity_type, entity_id, details_json) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$userId, $action, $entityType, $entityId, $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
}

function crm_public_employee(array $row, bool $includeFinance): array {
    $employee = [
        'id' => $row['id'],
        'name' => $row['full_name'],
        'role' => $row['role_name'],
        'department' => $row['department'],
        'phone' => $row['phone'],
        'email' => $row['email'],
        'avatarKey' => $row['avatar_key'],
        'attendanceMode' => $row['attendance_mode'],
        'active' => (bool)$row['active'],
        'notes' => $row['notes'],
    ];
    if ($includeFinance) {
        $employee['payRate'] = ((int)$row['pay_rate_cents']) / 100;
        $employee['advanceAmount'] = ((int)$row['advance_amount_cents']) / 100;
    }
    return $employee;
}

function crm_public_crews(): array {
    $crews = crm_db()->query('SELECT id, name, specialty, foreman_id, phone, notes, active FROM crm_crews ORDER BY active DESC, name')->fetchAll();
    $members = crm_db()->query('SELECT crew_id, employee_id FROM crm_crew_members ORDER BY joined_at')->fetchAll();
    $memberIds = [];
    foreach ($members as $member) $memberIds[$member['crew_id']][] = $member['employee_id'];
    return array_map(static fn(array $crew): array => [
        'id' => $crew['id'],
        'name' => $crew['name'],
        'specialty' => $crew['specialty'],
        'leadId' => $crew['foreman_id'] ?: '',
        'phone' => $crew['phone'],
        'notes' => $crew['notes'],
        'active' => (bool)$crew['active'],
        'memberIds' => $memberIds[$crew['id']] ?? [],
    ], $crews);
}

function crm_money_cents(mixed $value): int {
    if (!is_numeric($value)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте сумму.'], 422);
    $number = round((float)$value * 100);
    if ($number < 0 || $number > 1000000000) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте сумму.'], 422);
    return (int)$number;
}
