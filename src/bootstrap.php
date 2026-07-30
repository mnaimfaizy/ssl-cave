<?php

declare(strict_types=1);

if (!extension_loaded('pdo_mysql')) {
    $msg = "SSL Cave requires the pdo_mysql PHP extension.\nEnable it in cPanel → Select PHP Version → Extensions.\n";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $msg;
    }
    exit(1);
}

$configPath = dirname(__DIR__) . '/config.php';
if (!is_readable($configPath)) {
    $msg = "SSL Cave is not configured.\n"
        . "Copy config.php.example to config.php in the subdomain folder and fill in values.\n"
        . "Document root must be this subdomain folder, not a nested public/ directory.\n";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $msg;
    }
    exit(1);
}

/** @var array<string,mixed> $config */
$config = require $configPath;
$GLOBALS['sslcave_config'] = $config;

date_default_timezone_set((string) ($config['timezone'] ?? 'UTC'));

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AcmeAdapter.php';
require_once __DIR__ . '/CpanelAdapter.php';
require_once __DIR__ . '/SyncService.php';
require_once __DIR__ . '/JobQueue.php';
require_once __DIR__ . '/AlertService.php';

if (PHP_SAPI === 'cli') {
    return;
}

Auth::startSession();

header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
