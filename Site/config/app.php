<?php

declare(strict_types=1);

return [
    'app_env' => $_ENV['APP_ENV'] ?? 'development',
    'app_url' => $_ENV['APP_URL'] ?? 'http://localhost/Pericles/Site/public',
    'access_token_ttl' => max(60, (int) ($_ENV['ACCESS_TOKEN_TTL'] ?? 3600)),
    'login_rate_limit' => max(1, (int) ($_ENV['LOGIN_RATE_LIMIT'] ?? 5)),
    'login_rate_window' => max(60, (int) ($_ENV['LOGIN_RATE_WINDOW'] ?? 300)),
    'device_challenge_ttl' => max(30, min(60, (int) ($_ENV['DEVICE_CHALLENGE_TTL'] ?? 60))),
    'max_devices_per_user' => max(1, (int) ($_ENV['MAX_DEVICES_PER_USER'] ?? 3)),
    'activation_rate_limit' => max(1, (int) ($_ENV['ACTIVATION_RATE_LIMIT'] ?? 10)),
    'activation_rate_window' => max(60, (int) ($_ENV['ACTIVATION_RATE_WINDOW'] ?? 900)),
    'module_ticket_ttl' => max(10, min(120, (int) ($_ENV['MODULE_TICKET_TTL'] ?? 30))),
    'max_module_size_bytes' => max(1, (int) ($_ENV['MAX_MODULE_SIZE_MB'] ?? 50)) * 1024 * 1024,
    'module_ticket_rate_limit' => max(1, (int) ($_ENV['MODULE_TICKET_RATE_LIMIT'] ?? 20)),
    'module_download_rate_limit' => max(1, (int) ($_ENV['MODULE_DOWNLOAD_RATE_LIMIT'] ?? 10)),
    'module_rate_window' => max(10, (int) ($_ENV['MODULE_RATE_WINDOW'] ?? 60)),
    'module_signing_key_id' => $_ENV['MODULE_SIGNING_KEY_ID'] ?? 'pericles-modules-development-test',
    'module_signing_private_key_path' => $_ENV['MODULE_SIGNING_PRIVATE_KEY_PATH'] ?? '',
    'password_reset_ttl' => max(300, (int) ($_ENV['PASSWORD_RESET_TTL'] ?? 1800)),
    'password_reset_limit' => max(1, (int) ($_ENV['PASSWORD_RESET_LIMIT'] ?? 3)),
    'mail_from' => $_ENV['MAIL_FROM'] ?? 'no-reply@pericles.gg',
    'payment_provider' => trim((string) ($_ENV['PAYMENT_PROVIDER'] ?? '')),
    'payment_checkout_url' => trim((string) ($_ENV['PAYMENT_CHECKOUT_URL'] ?? '')),
    'payment_webhook_secret' => (string) ($_ENV['PAYMENT_WEBHOOK_SECRET'] ?? ''),
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => (int)($_ENV['DB_PORT'] ?? 3306),
        'name' => $_ENV['DB_NAME'] ?? 'pericles',
        'user' => $_ENV['DB_USER'] ?? 'root',
        'pass' => $_ENV['DB_PASS'] ?? '',
        'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
    ],
];
