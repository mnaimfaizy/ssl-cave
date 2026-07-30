<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3307';
$dbName = getenv('DB_NAME') ?: 'sslcave';
$dbUser = getenv('DB_USER') ?: 'sslcave';
$dbPass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : 'sslcave';

$fixtureRoot = $root . '/docker/fixtures';
$acmeBin = $fixtureRoot . '/bin/acme.sh';
$uapiBin = $fixtureRoot . '/bin/uapi';

foreach ([$acmeBin, $uapiBin] as $bin) {
    if (is_file($bin) && !is_executable($bin)) {
        @chmod($bin, 0755);
    }
}

$passwordHash = password_hash('test-password', PASSWORD_DEFAULT);

$GLOBALS['sslcave_config'] = [
    'app_name' => 'SSL Cave',
    'base_url' => 'http://localhost',
    'timezone' => 'UTC',
    'auth' => [
        'username' => 'admin',
        'password_hash' => $passwordHash,
        'session_name' => 'sslcave_test_sess',
        'lockout_max_attempts' => 5,
        'lockout_window_seconds' => 900,
    ],
    'db' => [
        'dsn' => sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbHost,
            $dbPort,
            $dbName
        ),
        'user' => $dbUser,
        'pass' => $dbPass,
    ],
    'paths' => [
        'cpanel_user' => 'docker',
        'home' => $root,
        'acme_home' => $fixtureRoot . '/acme.sh',
        'acme_bin' => $acmeBin,
        'uapi_bin' => $uapiBin,
        'storage' => $root . '/storage',
    ],
    'alerts' => [
        'email' => '',
        'from' => 'sslcave@example.com',
        'expiry_days' => [30, 14, 7],
    ],
    'jobs' => [
        'allowed_actions' => ['issue', 'renew', 'deploy', 'remove'],
        'max_runtime_seconds' => 600,
        'log_dir' => $root . '/storage/logs',
    ],
];

date_default_timezone_set('UTC');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('sslcave_test_sess');
    session_start();
}
