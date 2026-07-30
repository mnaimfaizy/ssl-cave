<?php

declare(strict_types=1);

final class AlertService
{
    /** @return array{sent:int,failed:int,skipped_dup:int,to?:string,skipped?:string} */
    public function run(): array
    {
        $email = Database::setting('alert_email', (string) config_get('alerts.email', ''));
        if ($email === null || trim($email) === '') {
            return ['sent' => 0, 'failed' => 0, 'skipped_dup' => 0, 'skipped' => 'no alert email configured'];
        }
        $email = trim($email);
        $from = (string) config_get('alerts.from', 'sslcave@localhost');
        $thresholds = config_get('alerts.expiry_days', [30, 14, 7]);
        if (!is_array($thresholds)) {
            $thresholds = [30, 14, 7];
        }

        $sent = 0;
        $failed = 0;
        $skippedDup = 0;
        $pdo = Database::pdo();
        $domains = $pdo->query('SELECT * FROM domains ORDER BY domain ASC')->fetchAll();

        foreach ($domains as $domain) {
            $name = (string) $domain['domain'];
            $status = (string) $domain['status'];

            // Drift / one-sided inventory — stable ref so weekly date churn does not re-mail.
            if ($status === 'drift' || $status === 'acme-only' || $status === 'cpanel-only') {
                $ref = $status . ':hook' . ((int) $domain['deploy_hook']);
                $result = $this->sendOnce(
                    $name,
                    'drift',
                    $ref,
                    $email,
                    $from,
                    "SSL Cave drift: {$name}",
                    "Domain {$name} shows status \"{$status}\".\n"
                    . 'cPanel expiry: ' . ($domain['cpanel_expiry'] ?? 'n/a') . "\n"
                    . 'acme.sh expiry: ' . ($domain['acme_expiry'] ?? 'n/a') . "\n"
                    . 'Deploy hook: ' . ((int) $domain['deploy_hook'] === 1 ? 'yes' : 'no') . "\n"
                );
                if ($result === 'sent') {
                    $sent++;
                } elseif ($result === 'failed') {
                    $failed++;
                } else {
                    $skippedDup++;
                }
            }

            // Expiry thresholds
            $expiry = $domain['acme_expiry'] ?? $domain['cpanel_expiry'] ?? null;
            $days = days_until(is_string($expiry) ? $expiry : null);
            if ($days !== null) {
                foreach ($thresholds as $threshold) {
                    $t = (int) $threshold;
                    if ($days <= $t) {
                        $ref = (string) $expiry . ':' . $t;
                        $result = $this->sendOnce(
                            $name,
                            'expiry_' . $t,
                            $ref,
                            $email,
                            $from,
                            "SSL Cave expiry ({$t}d): {$name}",
                            "Certificate for {$name} expires in {$days} day(s) (on {$expiry}).\n"
                        );
                        if ($result === 'sent') {
                            $sent++;
                        } elseif ($result === 'failed') {
                            $failed++;
                        } else {
                            $skippedDup++;
                        }
                    }
                }
            }
        }

        // Job failures since last run window (24h)
        $jobs = $pdo->query(
            "SELECT * FROM jobs WHERE status = 'failed' AND finished_at >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)"
        )->fetchAll();
        foreach ($jobs as $job) {
            $name = (string) $job['domain'];
            $ref = 'job:' . $job['id'];
            $result = $this->sendOnce(
                $name,
                'job_failure',
                $ref,
                $email,
                $from,
                "SSL Cave job failed: {$name}",
                "Job #{$job['id']} ({$job['action']}) for {$name} failed.\n"
                . 'Exit code: ' . ($job['exit_code'] ?? 'n/a') . "\n\n"
                . "stderr:\n" . substr((string) ($job['stderr'] ?? ''), 0, 4000) . "\n"
            );
            if ($result === 'sent') {
                $sent++;
            } elseif ($result === 'failed') {
                $failed++;
            } else {
                $skippedDup++;
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'skipped_dup' => $skippedDup,
            'to' => $email,
        ];
    }

    /** @return 'sent'|'failed'|'duplicate' */
    private function sendOnce(
        string $domain,
        string $type,
        string $refKey,
        string $to,
        string $from,
        string $subject,
        string $body
    ): string {
        $pdo = Database::pdo();

        // Already delivered (or previously claimed) for this ref — do not resend.
        $check = $pdo->prepare(
            'SELECT id FROM alert_sent WHERE domain = ? AND alert_type = ? AND ref_key = ? LIMIT 1'
        );
        $check->execute([$domain, $type, $refKey]);
        if ($check->fetch() !== false) {
            return 'duplicate';
        }

        $headers = 'From: ' . $from . "\r\n"
            . 'Reply-To: ' . $from . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";

        // -f sets envelope sender; helps shared-hosting delivery when From is on-account.
        $ok = mail($to, $subject, $body, $headers, '-f' . $from);
        if (!$ok) {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "mail() failed for {$domain} / {$type} → {$to}\n");
            }
            return 'failed';
        }

        try {
            $ins = $pdo->prepare(
                'INSERT INTO alert_sent (domain, alert_type, ref_key, sent_at) VALUES (?, ?, ?, ?)'
            );
            $ins->execute([$domain, $type, $refKey, now_utc()]);
        } catch (PDOException) {
            // Race with another worker — treat as duplicate after successful mail.
            return 'duplicate';
        }

        return 'sent';
    }
}
