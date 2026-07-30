#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cron: expiry / drift / job-failure emails.
 *
 * Suggested crontab (on many cPanel hosts use /usr/local/bin/php):
 *   20 8 * * * /usr/local/bin/php /home/USER/<docroot>/bin/alert.php >/dev/null 2>&1
 *
 * Keep existing acme.sh --cron for routine renewals. Do not add a portal renew-all.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    // Refresh inventory lightly before alerting
    try {
        (new SyncService())->sync();
    } catch (Throwable $e) {
        fwrite(STDERR, 'Sync warning: ' . $e->getMessage() . "\n");
    }

    $result = (new AlertService())->run();
    if (isset($result['skipped'])) {
        echo 'Skipped: ' . $result['skipped'] . "\n";
        exit(0);
    }
    echo sprintf(
        "Alert email: %s\nSent: %d\nFailed: %d\nAlready sent (skipped): %d\n",
        (string) ($result['to'] ?? ''),
        (int) $result['sent'],
        (int) ($result['failed'] ?? 0),
        (int) ($result['skipped_dup'] ?? 0)
    );
    exit(((int) ($result['failed'] ?? 0)) > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Alert error: ' . $e->getMessage() . "\n");
    exit(1);
}
