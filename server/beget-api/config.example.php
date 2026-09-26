<?php
declare(strict_types=1);

return [
    'db_host' => 'localhost',
    'db_name' => 'eft_crm',
    'db_user' => 'eft_crm_user',
    'db_password' => 'replace-me',
    'app_secret' => 'replace-with-at-least-32-random-characters',
    'cookie_domain' => '.eftsip.ru',
    'allowed_origins' => [
        'https://crm.eftsip.ru',
    ],
    // Secrets stay only in config.local.php on the server. Never commit real values.
    'integrations' => [
        'mail' => [
            'enabled' => false,
            'from' => 'your-mailbox@example.com',
            'sender_name' => 'ЭФТ',
            'smtp_host' => 'smtp.mail.ru', 'smtp_port' => 465,
            'smtp_user' => 'your-mailbox@example.com', 'smtp_password' => '',
            'imap_host' => 'imap.mail.ru', 'imap_port' => 993,
            'imap_user' => 'your-mailbox@example.com', 'imap_password' => '',
        ],
        'telegram' => ['enabled' => false, 'bot_token' => '', 'site_chat_ids' => []],
        'whatsapp' => ['enabled' => false, 'access_token' => '', 'phone_number_id' => '', 'api_version' => ''],
        'max' => ['enabled' => false, 'bot_token' => '', 'site_chat_ids' => []],
    ],
];
