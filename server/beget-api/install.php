<?php
declare(strict_types=1);

header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$configPath = __DIR__ . '/config.local.php';
$installed = is_file($configPath);
$errorMessage = '';
$success = false;

session_name('EFTCRMINSTALL');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/api/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

function install_value(string $key, int $max = 190): string {
    return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max);
}

function install_config_source(array $config): string {
    return "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
}

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $csrf = (string)($_POST['csrf'] ?? '');
        if (!hash_equals((string)$_SESSION['install_csrf'], $csrf)) {
            throw new RuntimeException('Страница устарела. Обновите её и повторите установку.');
        }

        $dbPassword = (string)($_POST['db_password'] ?? '');
        $username = install_value('admin_username', 96);
        $displayName = install_value('admin_display_name', 180);
        $adminPassword = (string)($_POST['admin_password'] ?? '');
        $adminPasswordConfirm = (string)($_POST['admin_password_confirm'] ?? '');

        if ($dbPassword === '') throw new RuntimeException('Введите пароль базы данных.');
        if (!preg_match('/^[a-zA-Z0-9._-]{3,96}$/', $username)) {
            throw new RuntimeException('Логин администратора: от 3 символов, латинские буквы, цифры, точка, дефис или подчёркивание.');
        }
        if ($displayName === '') throw new RuntimeException('Введите имя администратора.');
        if (strlen($adminPassword) < 12) throw new RuntimeException('Пароль администратора должен содержать не менее 12 символов.');
        if (!hash_equals($adminPassword, $adminPasswordConfirm)) throw new RuntimeException('Пароли администратора не совпадают.');

        $dbName = 'mishin7t_crmapp';
        $dbUser = 'mishin7t_crmapp';
        $pdo = new PDO(
            'mysql:host=localhost;dbname=' . $dbName . ';charset=utf8mb4',
            $dbUser,
            $dbPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );

        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) throw new RuntimeException('Не найден файл схемы базы данных.');
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $schema, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') $pdo->exec($statement);
        }

        $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
        $admin = $pdo->prepare(
            "INSERT INTO crm_users (username, display_name, password_hash, role, active) VALUES (?, ?, ?, 'owner', 1) " .
            "ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), password_hash = VALUES(password_hash), role = 'owner', active = 1"
        );
        $admin->execute([$username, $displayName, $passwordHash]);

        $config = [
            'db_host' => 'localhost',
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_password' => $dbPassword,
            'app_secret' => bin2hex(random_bytes(48)),
            'cookie_domain' => '.eftsip.ru',
            'allowed_origins' => ['https://crm.eftsip.ru'],
        ];
        $temporaryPath = $configPath . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporaryPath, install_config_source($config), LOCK_EX) === false) {
            throw new RuntimeException('Не удалось сохранить конфигурацию. Проверьте права каталога api.');
        }
        @chmod($temporaryPath, 0600);
        if (!rename($temporaryPath, $configPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Не удалось включить конфигурацию CRM.');
        }

        $_SESSION = [];
        session_destroy();
        $success = true;
        $installed = true;
    } catch (Throwable $error) {
        error_log('EFT CRM install error: ' . $error->getMessage());
        $safeMessages = [
            'Страница устарела. Обновите её и повторите установку.',
            'Введите пароль базы данных.',
            'Логин администратора: от 3 символов, латинские буквы, цифры, точка, дефис или подчёркивание.',
            'Введите имя администратора.',
            'Пароль администратора должен содержать не менее 12 символов.',
            'Пароли администратора не совпадают.',
            'Не найден файл схемы базы данных.',
            'Не удалось сохранить конфигурацию. Проверьте права каталога api.',
            'Не удалось включить конфигурацию CRM.',
        ];
        $errorMessage = in_array($error->getMessage(), $safeMessages, true)
            ? $error->getMessage()
            : 'Не удалось подключиться к базе. Проверьте пароль и повторите попытку.';
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Установка ЭФТ CRM</title>
  <style>
    :root { color-scheme: light; font-family: Inter, system-ui, sans-serif; background: #f3f7f8; color: #17252a; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; }
    main { width: min(560px, 100%); background: #fff; border: 1px solid #dce7e9; border-radius: 20px; padding: 30px; box-shadow: 0 18px 45px rgba(26, 54, 60, .10); }
    .brand { color: #0d8f83; font-weight: 800; letter-spacing: .06em; }
    h1 { margin: 8px 0 10px; font-size: 28px; }
    p { color: #60747a; line-height: 1.5; }
    label { display: grid; gap: 7px; margin-top: 16px; font-weight: 650; }
    input { width: 100%; border: 1px solid #c9dadd; border-radius: 10px; padding: 12px 14px; font: inherit; }
    input:focus { outline: 3px solid rgba(13, 143, 131, .14); border-color: #0d8f83; }
    button, .button { display: block; width: 100%; margin-top: 22px; border: 0; border-radius: 10px; padding: 13px 16px; background: #0d8f83; color: white; font: inherit; font-weight: 750; text-align: center; text-decoration: none; cursor: pointer; }
    .message { padding: 12px 14px; border-radius: 10px; margin-top: 18px; }
    .error { background: #fff0ee; color: #a22b20; }
    .success { background: #eaf8f5; color: #116b62; }
    .note { font-size: 13px; }
  </style>
</head>
<body>
<main>
  <div class="brand">ЭФТ CRM</div>
  <?php if ($success): ?>
    <h1>Сервер настроен</h1>
    <div class="message success">Таблицы созданы, владелец CRM добавлен, установщик закрыт.</div>
    <a class="button" href="api.php?action=health">Проверить API</a>
  <?php elseif ($installed): ?>
    <h1>CRM уже установлена</h1>
    <p>Повторная установка отключена. Конфигурация и данные не изменялись.</p>
    <a class="button" href="api.php?action=health">Проверить API</a>
  <?php else: ?>
    <h1>Первичная настройка</h1>
    <p>Пароли передаются только вашему серверу по HTTPS и записываются в закрытую конфигурацию. После успешной установки эта форма отключится.</p>
    <?php if ($errorMessage !== ''): ?><div class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars((string)$_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
      <label>Пароль базы mishin7t_crmapp
        <input type="password" name="db_password" required autocomplete="new-password">
      </label>
      <label>Логин владельца CRM
        <input type="text" name="admin_username" value="owner" required maxlength="96" autocomplete="username">
      </label>
      <label>Имя владельца
        <input type="text" name="admin_display_name" value="Администратор ЭФТ" required maxlength="180">
      </label>
      <label>Новый пароль владельца CRM
        <input type="password" name="admin_password" required minlength="12" autocomplete="new-password">
      </label>
      <label>Повторите пароль владельца
        <input type="password" name="admin_password_confirm" required minlength="12" autocomplete="new-password">
      </label>
      <button type="submit">Установить CRM</button>
    </form>
    <p class="note">Логин и пароль владельца понадобятся для входа в рабочую CRM после подключения интерфейса.</p>
  <?php endif; ?>
</main>
</body>
</html>
