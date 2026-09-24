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
        'admin' => ['employees.manage','crews.manage','clients.manage','tasks.manage','attendance.manage','procurement.manage','integrations.manage','audit.view'],
        'finance' => ['employees.view','attendance.view','finance.view','finance.manage','procurement.view'],
        'manager' => ['employees.view','clients.manage','tasks.manage','attendance.self','procurement.view'],
        'production' => ['employees.view','crews.view','tasks.manage','attendance.manage','procurement.view'],
        'procurement' => ['employees.view','clients.view','tasks.view','procurement.manage'],
        'foreman' => ['employees.view','crews.view','tasks.crew','attendance.self','procurement.view'],
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

function crm_schema_ensure_v30(): void {
    static $ready = false;
    if ($ready) return;
    crm_settings_ensure();
    $db = crm_db();
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    if ($schema === false) throw new RuntimeException('CRM schema file is unavailable.');
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement !== '') $db->exec($statement);
    }
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
            'construction_stage_id' => "CHAR(36) NULL AFTER crew_id",
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
        'crm_sites' => [
            'construction_status' => "ENUM('planning','active','paused','complete') NOT NULL DEFAULT 'planning' AFTER address",
            'manager_employee_id' => "CHAR(36) NULL AFTER construction_status",
            'contract_number' => "VARCHAR(120) NOT NULL DEFAULT '' AFTER manager_employee_id",
            'planned_start' => "DATE NULL AFTER contract_number",
            'planned_finish' => "DATE NULL AFTER planned_start",
            'actual_start' => "DATE NULL AFTER planned_finish",
            'actual_finish' => "DATE NULL AFTER actual_start",
            'construction_notes' => "TEXT NULL AFTER actual_finish",
        ],
    ];
    foreach ($columns as $table => $definitions) foreach ($definitions as $column => $definition) {
        if (!crm_column_exists($table, $column)) $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
    foreach (['workspace_revision' => '0', 'workspace_initialized' => '0', 'inventory_revision' => '0', 'inventory_initialized' => '0'] as $key => $value) {
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
    crm_schema_ensure_v30();
    $db = crm_db();
    $clients = array_map(static fn(array $row): array => [
        'id' => $row['id'], 'name' => $row['display_name'], 'phone' => $row['phone'], 'email' => $row['email'],
    ], $db->query('SELECT id, display_name, phone, email FROM crm_clients ORDER BY created_at')->fetchAll());
    $sites = array_map(static fn(array $row): array => [
        'id' => $row['id'], 'clientId' => $row['client_id'], 'name' => $row['name'], 'address' => $row['address'],
        'status' => $row['construction_status'], 'managerId' => $row['manager_employee_id'] ?: '', 'contractNumber' => $row['contract_number'],
        'plannedStart' => $row['planned_start'] ?: '', 'plannedFinish' => $row['planned_finish'] ?: '', 'actualStart' => $row['actual_start'] ?: '',
        'actualFinish' => $row['actual_finish'] ?: '', 'notes' => $row['construction_notes'] ?: '',
    ], $db->query('SELECT id, client_id, name, address, construction_status, manager_employee_id, contract_number, planned_start, planned_finish, actual_start, actual_finish, construction_notes FROM crm_sites ORDER BY created_at')->fetchAll());
    $constructionStages = array_map(static function (array $row): array {
        $dependencies = json_decode((string)($row['dependency_ids_json'] ?? '[]'), true); $comments = json_decode((string)($row['comments_json'] ?? '[]'), true); $attachments = json_decode((string)($row['attachments_json'] ?? '[]'), true);
        return ['id' => $row['id'], 'siteId' => $row['site_id'], 'title' => $row['title'], 'group' => $row['stage_group'], 'status' => $row['stage_status'], 'progress' => (int)$row['progress'],
          'plannedStart' => $row['planned_start'] ?: '', 'plannedFinish' => $row['planned_finish'] ?: '', 'actualStart' => $row['actual_start'] ?: '', 'actualFinish' => $row['actual_finish'] ?: '',
          'assigneeId' => $row['assignee_id'] ?: '', 'crewId' => $row['crew_id'] ?: '', 'dependencyIds' => is_array($dependencies) ? $dependencies : [], 'notes' => $row['notes'] ?: '',
          'blockReason' => $row['block_reason'] ?: '', 'comments' => is_array($comments) ? $comments : [], 'attachments' => is_array($attachments) ? $attachments : [],
          'createdAt' => crm_db_datetime($row['created_at'], true), 'updatedAt' => crm_db_datetime($row['updated_at'], true)];
    }, $db->query('SELECT * FROM crm_construction_stages ORDER BY planned_start, created_at')->fetchAll());
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
            'id' => $row['id'], 'orderId' => $row['order_id'] ?: '', 'siteId' => $row['site_id'] ?: '', 'constructionStageId' => $row['construction_stage_id'] ?: '', 'crewId' => $row['crew_id'] ?: '', 'title' => $row['title'], 'description' => $row['description'],
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
        'clients' => $clients, 'sites' => $sites, 'leads' => $leads, 'orders' => $orders, 'tasks' => $tasks, 'activities' => $activities, 'constructionStages' => $constructionStages,
        'workspaceRevision' => (int)($settings['workspace_revision'] ?? 0), 'workspaceInitialized' => ($settings['workspace_initialized'] ?? '0') === '1',
    ];
}

function crm_inventory_lines(mixed $value, array $materialIds): array {
    if (!is_array($value) || !$value || count($value) > 500) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Добавьте позиции документа.'], 422);
    $lines = [];
    $seen = [];
    foreach ($value as $row) {
        if (!is_array($row)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте позиции документа.'], 422);
        $itemId = crm_required_text($row['itemId'] ?? '', 'материал', 120);
        if (!isset($materialIds[$itemId]) || isset($seen[$itemId])) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Материал документа не найден или повторяется.'], 422);
        $quantity = (float)($row['quantity'] ?? 0); $price = (float)($row['price'] ?? -1);
        if ($quantity <= 0 || $quantity > 1000000 || $price < 0 || $price > 1000000000) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте количество и цену.'], 422);
        $seen[$itemId] = true;
        $lines[] = ['itemId' => $itemId, 'name' => crm_required_text($row['name'] ?? '', 'название материала', 300), 'unit' => crm_required_text($row['unit'] ?? '', 'единица', 50), 'quantity' => $quantity, 'price' => round($price, 2)];
    }
    return $lines;
}

function crm_public_inventory(): array {
    crm_schema_ensure_v30();
    $db = crm_db();
    $decode = static function (mixed $value): array { $rows = json_decode((string)$value, true); return is_array($rows) ? $rows : []; };
    $materials = array_map(static fn(array $row): array => [
        'id' => $row['id'], 'name' => $row['name'], 'category' => $row['category'], 'unit' => $row['unit_name'],
        'price' => ((int)$row['price_cents']) / 100, 'tracked' => (bool)$row['tracked'], 'minStock' => (float)$row['min_stock'],
        'source' => $row['source_name'], 'priceNote' => $row['price_note'],
    ], $db->query('SELECT * FROM crm_materials ORDER BY category, name')->fetchAll());
    $suppliers = array_map(static fn(array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'contact' => $row['contact_text'], 'notes' => $row['notes']], $db->query('SELECT * FROM crm_suppliers ORDER BY name')->fetchAll());
    $needs = array_map(static function (array $row) use ($decode): array { return [
        'id' => $row['id'], 'name' => $row['name'], 'kind' => $row['need_kind'], 'destination' => $row['destination'],
        'quantity' => (float)$row['quantity'], 'unit' => $row['unit_name'], 'priority' => $row['priority'], 'status' => $row['need_status'],
        'neededBy' => $row['needed_by'] ?: '', 'requestedBy' => $row['requested_by'], 'links' => $decode($row['links_json']), 'note' => $row['note'],
        'createdAt' => crm_db_datetime($row['created_at'], true), 'updatedAt' => crm_db_datetime($row['updated_at'], true),
    ]; }, $db->query('SELECT * FROM crm_supply_needs ORDER BY created_at DESC')->fetchAll());
    $purchases = array_map(static function (array $row) use ($decode): array { return [
        'id' => $row['id'], 'number' => $row['public_number'], 'date' => $row['purchase_date'], 'dueDate' => $row['due_date'] ?: '',
        'supplierId' => $row['supplier_id'], 'lines' => $decode($row['lines_json']), 'note' => $row['note'], 'createdAt' => crm_db_datetime($row['created_at'], true),
    ]; }, $db->query('SELECT * FROM crm_purchases ORDER BY created_at')->fetchAll());
    $documents = array_map(static function (array $row) use ($decode): array { return [
        'id' => $row['id'], 'number' => $row['public_number'], 'date' => $row['document_date'], 'kind' => $row['document_kind'],
        'target' => $row['target_name'], 'supplierId' => $row['supplier_id'] ?: '', 'purchaseId' => $row['purchase_id'] ?: '', 'orderId' => $row['order_id'] ?: '',
        'reference' => $row['reference_text'], 'note' => $row['note'], 'lines' => $decode($row['lines_json']), 'total' => ((int)$row['total_cents']) / 100,
        'createdAt' => crm_db_datetime($row['created_at'], true),
    ]; }, $db->query('SELECT * FROM crm_stock_documents ORDER BY created_at')->fetchAll());
    $tools = array_map(static fn(array $row): array => [
        'id' => $row['id'], 'name' => $row['name'], 'serial' => $row['inventory_number'], 'home' => $row['home_kind'],
        'price' => ((int)$row['price_cents']) / 100, 'note' => $row['note'], 'holderType' => $row['holder_type'],
        'holderId' => $row['holder_id'] ?: '', 'dueDate' => $row['due_date'] ?: '',
    ], $db->query('SELECT * FROM crm_tools ORDER BY name')->fetchAll());
    $events = array_map(static fn(array $row): array => [
        'id' => $row['id'], 'toolId' => $row['tool_id'], 'toolName' => $row['tool_name'], 'serial' => $row['inventory_number'],
        'kind' => $row['event_kind'], 'holder' => $row['holder_name'], 'date' => $row['event_date'], 'dueDate' => $row['due_date'] ?: '', 'note' => $row['note'],
    ], $db->query('SELECT * FROM crm_tool_events ORDER BY created_at')->fetchAll());
    $settings = $db->query("SELECT setting_key, setting_value FROM crm_settings WHERE setting_key IN ('inventory_revision','inventory_initialized')")->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'materials' => $materials, 'suppliers' => $suppliers, 'supplyNeeds' => $needs, 'purchases' => $purchases,
        'stockDocuments' => $documents, 'tools' => $tools, 'toolEvents' => $events,
        'inventoryRevision' => (int)($settings['inventory_revision'] ?? 0), 'inventoryInitialized' => ($settings['inventory_initialized'] ?? '0') === '1',
    ];
}

function crm_inventory_save(array $inventory, array $user, int $baseRevision): array {
    crm_schema_ensure_v30();
    $db = crm_db();
    $materials = crm_workspace_rows($inventory['materials'] ?? null, 'материалы', 20000);
    $suppliers = crm_workspace_rows($inventory['suppliers'] ?? null, 'поставщики', 10000);
    $needs = crm_workspace_rows($inventory['supplyNeeds'] ?? null, 'потребности', 30000);
    $purchases = crm_workspace_rows($inventory['purchases'] ?? null, 'закупки', 30000);
    $documents = crm_workspace_rows($inventory['stockDocuments'] ?? null, 'движения', 50000);
    $tools = crm_workspace_rows($inventory['tools'] ?? null, 'инструмент', 30000);
    $events = crm_workspace_rows($inventory['toolEvents'] ?? null, 'история инструмента', 100000);
    $db->beginTransaction();
    try {
        $lock = $db->prepare("SELECT setting_key, setting_value FROM crm_settings WHERE setting_key IN ('inventory_revision','inventory_initialized') FOR UPDATE");
        $lock->execute(); $settings = $lock->fetchAll(PDO::FETCH_KEY_PAIR); $revision = (int)($settings['inventory_revision'] ?? 0);
        if ($revision !== $baseRevision) { $db->rollBack(); crm_json(['ok' => false, 'code' => 'inventory_conflict', 'message' => 'Склад уже изменил другой сотрудник. Данные обновлены; повторите действие.'], 409); }
        $db->exec('DELETE FROM crm_tool_events'); $db->exec('DELETE FROM crm_tools'); $db->exec('DELETE FROM crm_stock_documents');
        $db->exec('DELETE FROM crm_purchases'); $db->exec('DELETE FROM crm_supply_needs'); $db->exec('DELETE FROM crm_suppliers'); $db->exec('DELETE FROM crm_materials');
        $materialIds = []; $materialTracked = [];
        $insert = $db->prepare('INSERT INTO crm_materials (id,name,category,unit_name,price_cents,tracked,min_stock,source_name,price_note) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($materials as $row) {
            $id = crm_required_text($row['id'] ?? '', 'код материала', 120); if (isset($materialIds[$id])) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Код материала повторяется.'], 422);
            $price = (float)($row['price'] ?? -1); $minStock = (float)($row['minStock'] ?? -1); if ($price < 0 || $minStock < 0) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте цену и минимальный запас.'], 422);
            $materialIds[$id] = true; $materialTracked[$id] = !empty($row['tracked']); $insert->execute([$id, crm_required_text($row['name'] ?? '', 'материал', 300), crm_required_text($row['category'] ?? '', 'категория', 160), crm_required_text($row['unit'] ?? '', 'единица', 50), crm_money_cents($price), $materialTracked[$id] ? 1 : 0, $minStock, crm_text($row['source'] ?? '', 255), crm_text($row['priceNote'] ?? '', 500)]);
        }
        $supplierIds = [];
        $insert = $db->prepare('INSERT INTO crm_suppliers (id,name,contact_text,notes) VALUES (?,?,?,?)');
        foreach ($suppliers as $row) { $id = crm_required_text($row['id'] ?? '', 'идентификатор поставщика', 36); $supplierIds[$id] = true; $insert->execute([$id, crm_required_text($row['name'] ?? '', 'поставщик', 240), crm_text($row['contact'] ?? '', 500), crm_text($row['notes'] ?? '', 10000)]); }
        $insert = $db->prepare('INSERT INTO crm_supply_needs (id,name,need_kind,destination,quantity,unit_name,priority,need_status,needed_by,requested_by,links_json,note,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($needs as $row) {
            $kind = in_array($row['kind'] ?? '', ['tool','consumable','equipment','other'], true) ? $row['kind'] : 'other'; $destination = ($row['destination'] ?? '') === 'tp' ? 'tp' : 'production';
            $priority = in_array($row['priority'] ?? '', ['normal','high','urgent'], true) ? $row['priority'] : 'normal'; $status = in_array($row['status'] ?? '', ['requested','approved','ordered','received','rejected','cancelled'], true) ? $row['status'] : 'requested';
            $quantity = (float)($row['quantity'] ?? 0); if ($quantity <= 0 || $quantity > 1000000) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте количество потребности.'], 422);
            $links = is_array($row['links'] ?? null) ? array_slice(array_values($row['links']), 0, 10) : [];
            foreach ($links as &$link) { $link = crm_text($link, 2000); if (!filter_var($link, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($link, PHP_URL_SCHEME)), ['http','https'], true)) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте ссылки на товары.'], 422); } unset($link);
            $insert->execute([crm_required_text($row['id'] ?? '', 'идентификатор потребности', 36), crm_required_text($row['name'] ?? '', 'потребность', 300), $kind, $destination, $quantity, crm_required_text($row['unit'] ?? '', 'единица', 50), $priority, $status, empty($row['neededBy']) ? null : crm_date($row['neededBy']), crm_text($row['requestedBy'] ?? '', 200), json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), crm_text($row['note'] ?? '', 10000), crm_sql_datetime($row['createdAt'] ?? '') ?? date('Y-m-d H:i:s')]);
        }
        $purchaseIds = []; $purchaseLines = [];
        $insert = $db->prepare('INSERT INTO crm_purchases (id,public_number,purchase_date,due_date,supplier_id,lines_json,note,created_at) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($purchases as $row) { $id = crm_required_text($row['id'] ?? '', 'идентификатор закупки', 36); $supplierId = crm_required_text($row['supplierId'] ?? '', 'поставщик', 36); if (!isset($supplierIds[$supplierId])) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Поставщик закупки не найден.'], 422); $purchaseIds[$id] = true; $lines = crm_inventory_lines($row['lines'] ?? null, $materialIds); $purchaseLines[$id] = array_column($lines, 'quantity', 'itemId'); $insert->execute([$id, crm_required_text($row['number'] ?? '', 'номер закупки', 40), crm_date($row['date'] ?? ''), empty($row['dueDate']) ? null : crm_date($row['dueDate']), $supplierId, json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), crm_text($row['note'] ?? '', 10000), crm_sql_datetime($row['createdAt'] ?? '') ?? date('Y-m-d H:i:s')]); }
        $insert = $db->prepare('INSERT INTO crm_stock_documents (id,public_number,document_date,document_kind,target_name,supplier_id,purchase_id,order_id,reference_text,note,lines_json,total_cents,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stockLevels = []; $received = [];
        foreach ($documents as $row) {
            $kind = in_array($row['kind'] ?? '', ['receipt','issue','return','writeoff','direct'], true) ? $row['kind'] : ''; if ($kind === '') crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте вид движения.'], 422);
            $supplierId = crm_text($row['supplierId'] ?? '', 36); $purchaseId = crm_text($row['purchaseId'] ?? '', 36);
            if ($supplierId !== '' && !isset($supplierIds[$supplierId])) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Поставщик движения не найден.'], 422);
            if ($purchaseId !== '' && !isset($purchaseIds[$purchaseId])) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Закупка движения не найдена.'], 422);
            $lines = crm_inventory_lines($row['lines'] ?? null, $materialIds); $total = 0.0;
            foreach ($lines as $line) {
                $itemId = $line['itemId']; $total += $line['quantity'] * $line['price'];
                if ($kind !== 'direct' && empty($materialTracked[$itemId])) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Включите складской учёт для всех позиций движения.'], 422);
                if (in_array($kind, ['receipt','return'], true)) $stockLevels[$itemId] = ($stockLevels[$itemId] ?? 0) + $line['quantity'];
                if (in_array($kind, ['issue','writeoff'], true)) { $stockLevels[$itemId] = ($stockLevels[$itemId] ?? 0) - $line['quantity']; if ($stockLevels[$itemId] < -0.0001) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Расход не может превышать складской остаток.'], 422); }
                if ($purchaseId !== '') { $received[$purchaseId][$itemId] = ($received[$purchaseId][$itemId] ?? 0) + $line['quantity']; if (!isset($purchaseLines[$purchaseId][$itemId]) || $received[$purchaseId][$itemId] > $purchaseLines[$purchaseId][$itemId] + 0.0001) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Поступление превышает количество в закупке.'], 422); }
            }
            $insert->execute([crm_required_text($row['id'] ?? '', 'идентификатор движения', 36), crm_required_text($row['number'] ?? '', 'номер движения', 40), crm_date($row['date'] ?? ''), $kind, crm_required_text($row['target'] ?? '', 'получатель или основание', 500), $supplierId ?: null, $purchaseId ?: null, crm_text($row['orderId'] ?? '', 36) ?: null, crm_text($row['reference'] ?? '', 500), crm_text($row['note'] ?? '', 10000), json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), crm_money_cents($total), crm_sql_datetime($row['createdAt'] ?? '') ?? date('Y-m-d H:i:s')]);
        }
        $toolIds = [];
        $insert = $db->prepare('INSERT INTO crm_tools (id,name,inventory_number,home_kind,price_cents,note,holder_type,holder_id,due_date) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($tools as $row) { $id = crm_required_text($row['id'] ?? '', 'идентификатор инструмента', 36); $toolIds[$id] = true; $holderType = in_array($row['holderType'] ?? '', ['employee','crew'], true) ? $row['holderType'] : ''; $insert->execute([$id, crm_required_text($row['name'] ?? '', 'инструмент', 300), crm_required_text($row['serial'] ?? '', 'инвентарный номер', 120), ($row['home'] ?? '') === 'field' ? 'field' : 'production', crm_money_cents($row['price'] ?? 0), crm_text($row['note'] ?? '', 10000), $holderType, $holderType === '' ? null : crm_text($row['holderId'] ?? '', 36), empty($row['dueDate']) ? null : crm_date($row['dueDate'])]); }
        $insert = $db->prepare('INSERT INTO crm_tool_events (id,tool_id,tool_name,inventory_number,event_kind,holder_name,event_date,due_date,note) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($events as $row) { $toolId = crm_required_text($row['toolId'] ?? '', 'инструмент события', 36); if (!isset($toolIds[$toolId])) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Инструмент события не найден.'], 422); $kind = ($row['kind'] ?? '') === 'return' ? 'return' : 'issue'; $insert->execute([crm_required_text($row['id'] ?? '', 'идентификатор события', 36), $toolId, crm_required_text($row['toolName'] ?? '', 'инструмент', 300), crm_required_text($row['serial'] ?? '', 'инвентарный номер', 120), $kind, crm_required_text($row['holder'] ?? '', 'получатель', 240), crm_date($row['date'] ?? ''), empty($row['dueDate']) ? null : crm_date($row['dueDate']), crm_text($row['note'] ?? '', 10000)]); }
        $nextRevision = $revision + 1; $db->prepare("UPDATE crm_settings SET setting_value = ? WHERE setting_key = 'inventory_revision'")->execute([(string)$nextRevision]); $db->prepare("UPDATE crm_settings SET setting_value = '1' WHERE setting_key = 'inventory_initialized'")->execute();
        $db->commit(); crm_audit((int)$user['id'], 'inventory.save', 'inventory', (string)$nextRevision, ['materials' => count($materials), 'documents' => count($documents), 'tools' => count($tools)]); return crm_public_inventory();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
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
    $constructionStages = array_values(array_filter($workspace['constructionStages'], static fn(array $stage): bool => isset($siteIds[$stage['siteId']])));
    return array_merge($workspace, ['clients' => $clients, 'sites' => $sites, 'leads' => [], 'orders' => $orders, 'tasks' => $tasks, 'activities' => $activities, 'constructionStages' => $constructionStages]);
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
    crm_schema_ensure_v30();
    $db = crm_db();
    $clients = crm_workspace_rows($workspace['clients'] ?? null, 'клиенты', 20000);
    $sites = crm_workspace_rows($workspace['sites'] ?? null, 'объекты', 30000);
    $leads = crm_workspace_rows($workspace['leads'] ?? null, 'заявки', 30000);
    $orders = crm_workspace_rows($workspace['orders'] ?? null, 'заказы', 30000);
    $tasks = crm_workspace_rows($workspace['tasks'] ?? null, 'задачи', 50000);
    $activities = crm_workspace_rows($workspace['activities'] ?? null, 'история', 100000);
    $constructionStages = crm_workspace_rows($workspace['constructionStages'] ?? [], 'этапы строительства', 50000);
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
        $db->exec('DELETE FROM crm_construction_stages');
        $db->exec('DELETE FROM crm_sites');
        $db->exec('DELETE FROM crm_clients');
        $insertClient = $db->prepare('INSERT INTO crm_clients (id, display_name, phone, email, source, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($clients as $row) $insertClient->execute([
            crm_required_text($row['id'] ?? '', 'идентификатор клиента', 36), crm_required_text($row['name'] ?? '', 'клиент', 200), crm_text($row['phone'] ?? '', 60), crm_text($row['email'] ?? '', 190), '', '', (int)$user['id'], crm_sql_datetime($row['createdAt'] ?? '') ?? date('Y-m-d H:i:s'),
        ]);
        $insertSite = $db->prepare('INSERT INTO crm_sites (id, client_id, name, address, construction_status, manager_employee_id, contract_number, planned_start, planned_finish, actual_start, actual_finish, construction_notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($sites as $row) $insertSite->execute([
            crm_required_text($row['id'] ?? '', 'идентификатор объекта', 36), crm_required_text($row['clientId'] ?? '', 'клиент объекта', 36), crm_required_text($row['name'] ?? '', 'объект', 220), crm_text($row['address'] ?? '', 500),
            in_array($row['status'] ?? '', ['planning','active','paused','complete'], true) ? $row['status'] : 'planning', isset($employeeIds[crm_text($row['managerId'] ?? '', 36)]) ? crm_text($row['managerId'], 36) : null,
            crm_text($row['contractNumber'] ?? '', 120), ($row['plannedStart'] ?? '') !== '' ? crm_date($row['plannedStart']) : null, ($row['plannedFinish'] ?? '') !== '' ? crm_date($row['plannedFinish']) : null,
            ($row['actualStart'] ?? '') !== '' ? crm_date($row['actualStart']) : null, ($row['actualFinish'] ?? '') !== '' ? crm_date($row['actualFinish']) : null, crm_text($row['notes'] ?? '', 10000), crm_sql_datetime($row['createdAt'] ?? '') ?? date('Y-m-d H:i:s'),
        ]);
        $siteIds = array_fill_keys(array_column($sites, 'id'), true); $crewIds = array_fill_keys($db->query('SELECT id FROM crm_crews')->fetchAll(PDO::FETCH_COLUMN), true);
        $insertStage = $db->prepare('INSERT INTO crm_construction_stages (id, site_id, title, stage_group, stage_status, progress, planned_start, planned_finish, actual_start, actual_finish, assignee_id, crew_id, dependency_ids_json, notes, block_reason, comments_json, attachments_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($constructionStages as $row) {
            $siteId = crm_required_text($row['siteId'] ?? '', 'объект этапа', 36); if (!isset($siteIds[$siteId])) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Объект этапа не найден.'], 422);
            $status = in_array($row['status'] ?? '', ['planned','ready','doing','blocked','review','done'], true) ? $row['status'] : 'planned'; $progress = (int)($row['progress'] ?? 0); if ($progress < 0 || $progress > 100) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте готовность этапа.'], 422);
            $assignee = crm_text($row['assigneeId'] ?? '', 36); $crew = crm_text($row['crewId'] ?? '', 36);
            $insertStage->execute([crm_required_text($row['id'] ?? '', 'идентификатор этапа', 36), $siteId, crm_required_text($row['title'] ?? '', 'название этапа', 250), crm_required_text($row['group'] ?? '', 'раздел этапа', 120), $status, $progress,
              ($row['plannedStart'] ?? '') !== '' ? crm_date($row['plannedStart']) : null, ($row['plannedFinish'] ?? '') !== '' ? crm_date($row['plannedFinish']) : null, ($row['actualStart'] ?? '') !== '' ? crm_date($row['actualStart']) : null, ($row['actualFinish'] ?? '') !== '' ? crm_date($row['actualFinish']) : null,
              isset($employeeIds[$assignee]) ? $assignee : null, isset($crewIds[$crew]) ? $crew : null, json_encode(is_array($row['dependencyIds'] ?? null) ? $row['dependencyIds'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), crm_text($row['notes'] ?? '', 10000), crm_text($row['blockReason'] ?? '', 10000),
              json_encode(is_array($row['comments'] ?? null) ? $row['comments'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode(is_array($row['attachments'] ?? null) ? $row['attachments'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), crm_sql_datetime($row['createdAt'] ?? '') ?? date('Y-m-d H:i:s'), crm_sql_datetime($row['updatedAt'] ?? '') ?? date('Y-m-d H:i:s')]);
        }
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
        $stageSites = $db->query('SELECT id, site_id FROM crm_construction_stages')->fetchAll(PDO::FETCH_KEY_PAIR);
        $insertTask = $db->prepare('INSERT INTO crm_tasks (id, order_id, title, description, site_id, assignee_id, crew_id, construction_stage_id, status, priority, due_at, quantity, completed_quantity, unit_name, block_reason, checklist_json, original_due_at, reschedule_history_json, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($tasks as $row) {
            $orderId = crm_text($row['orderId'] ?? '', 36); $assignee = crm_text($row['assigneeId'] ?? '', 36); if (!isset($employeeIds[$assignee])) $assignee = '';
            $siteId = crm_text($row['siteId'] ?? '', 36) ?: ($orderSites[$orderId] ?? ''); if (!isset($siteIds[$siteId])) $siteId = '';
            $crewId = crm_text($row['crewId'] ?? '', 36); if (!isset($crewIds[$crewId])) $crewId = '';
            $stageId = crm_text($row['constructionStageId'] ?? '', 36); if (!isset($stageSites[$stageId]) || $stageSites[$stageId] !== $siteId) $stageId = '';
            $status = in_array($row['status'] ?? '', ['planned','doing','review','blocked','done'], true) ? $row['status'] : 'planned';
            $priority = ($row['priority'] ?? '') === 'high' ? 'high' : 'normal';
            $quantity = (float)($row['quantity'] ?? 1); $completed = (float)($row['completedQty'] ?? 0);
            if ($quantity <= 0 || $completed < 0 || $completed > $quantity) crm_json(['ok' => false, 'code' => 'validation_failed', 'message' => 'Проверьте объём задания.'], 422);
            $insertTask->execute([crm_required_text($row['id'] ?? '', 'идентификатор задачи', 36), $orderId ?: null, crm_required_text($row['title'] ?? '', 'название задачи', 250), crm_text($row['description'] ?? '', 20000), $siteId ?: null, $assignee ?: null, $crewId ?: null, $stageId ?: null, $status, $priority, crm_sql_datetime($row['dueAt'] ?? ''), $quantity, $completed, crm_required_text($row['unit'] ?? 'задача', 'единица', 80), crm_text($row['blockReason'] ?? '', 10000), json_encode(is_array($row['checklist'] ?? null) ? $row['checklist'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), crm_sql_datetime($row['originalDueAt'] ?? ''), json_encode(is_array($row['rescheduleHistory'] ?? null) ? $row['rescheduleHistory'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$user['id'], crm_sql_datetime($row['createdAt'] ?? '') ?? date('Y-m-d H:i:s')]);
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
