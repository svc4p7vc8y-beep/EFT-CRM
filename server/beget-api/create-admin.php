<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';

$username = trim((string)getenv('CRM_ADMIN_USERNAME'));
$password = (string)getenv('CRM_ADMIN_PASSWORD');
$displayName = trim((string)getenv('CRM_ADMIN_NAME')) ?: 'Владелец ЭФТ';

if ($username === '' || strlen($password) < 12) {
    fwrite(STDERR, "Set CRM_ADMIN_USERNAME and CRM_ADMIN_PASSWORD (at least 12 characters).\n");
    exit(1);
}

$statement = crm_db()->prepare("INSERT INTO crm_users (username, display_name, password_hash, role, active) VALUES (?, ?, ?, 'owner', 1)");
$statement->execute([$username, $displayName, password_hash($password, PASSWORD_DEFAULT)]);
fwrite(STDOUT, "Owner account created. Remove environment variables from the shell history.\n");
