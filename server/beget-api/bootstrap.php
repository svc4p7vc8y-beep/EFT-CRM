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
