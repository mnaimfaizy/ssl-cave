#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cron worker: process one queued issue/renew job.
 *
 * Suggested crontab (not renew-all; many shared hosts min interval = 5 minutes):
 *   every 5 min: /usr/local/bin/php /home/USER/<docroot>/bin/worker.php >/dev/null 2>&1
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

$maxRuntime = (int) config_get('jobs.max_runtime_seconds', 600);
if ($maxRuntime > 0) {
    set_time_limit($maxRuntime);
}

try {
    // Heartbeat so the portal can warn when cron is missing / broken.
    Database::setSetting('worker_last_run_at', now_utc());

    $queue = new JobQueue();
    $result = $queue->processNext();
    if ($result === null) {
        echo "No pending jobs.\n";
        exit(0);
    }
    echo sprintf(
        "Job #%d %s %s => %s (exit %s)\n",
        $result['id'],
        $result['action'],
        $result['domain'],
        $result['status'],
        (string) $result['exit_code']
    );
    exit($result['status'] === 'success' ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Worker error: ' . $e->getMessage() . "\n");
    exit(1);
}
