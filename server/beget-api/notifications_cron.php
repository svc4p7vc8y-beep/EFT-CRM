<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';
crm_schema_ensure_v30();

try {
    $mail = crm_integration_config('mail');
    if (!empty($mail['enabled'])) crm_mail_sync($mail);
} catch (Throwable $error) {
    error_log('EFT CRM scheduled mail sync failed: ' . $error->getMessage());
}

crm_push_due_reminders();
crm_push_dispatch(50);
