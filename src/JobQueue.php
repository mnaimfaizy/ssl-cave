<?php

declare(strict_types=1);

final class JobQueue
{
    /** @param array<string,mixed> $payload */
    public function enqueue(string $domain, string $action, array $payload = []): int
    {
        $allowed = config_get('jobs.allowed_actions', ['issue', 'renew', 'deploy', 'remove']);
        if (!is_array($allowed) || !in_array($action, $allowed, true)) {
            throw new InvalidArgumentException('Action not allowlisted: ' . $action);
        }

        $domain = strtolower(trim($domain));
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+$/', $domain)) {
            throw new InvalidArgumentException('Invalid domain.');
        }

        if ($action === 'remove') {
            $payload = $this->normalizeRemovePayload($payload);
        }

        // Block duplicate pending/running jobs for same domain+action
        $pdo = Database::pdo();
        $check = $pdo->prepare(
            "SELECT id FROM jobs WHERE domain = ? AND action = ? AND status IN ('pending','running') LIMIT 1"
        );
        $check->execute([$domain, $action]);
        if ($check->fetch()) {
            throw new RuntimeException('A job is already pending or running for this domain.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO jobs (domain, action, payload, status, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $domain,
            $action,
            $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'pending',
            now_utc(),
        ]);

        $id = (int) $pdo->lastInsertId();

        // Overlay status
        $upd = $pdo->prepare('UPDATE domains SET status = ?, updated_at = ? WHERE domain = ?');
        $upd->execute(['job-pending', now_utc(), $domain]);

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 50): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM jobs ORDER BY id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM jobs WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function pendingCount(): int
    {
        $stmt = Database::pdo()->query(
            "SELECT COUNT(*) AS c FROM jobs WHERE status IN ('pending','running')"
        );
        return (int) ($stmt->fetch()['c'] ?? 0);
    }

    /**
     * Manually abandon a stuck pending/running job so a new one can be enqueued.
     *
     * @return array{id:int,status:string,exit_code:int,domain:string,action:string}
     */
    public function cancel(int $id, string $reason = 'Cancelled from portal.'): array
    {
        $job = $this->find($id);
        if ($job === null) {
            throw new RuntimeException('Job not found.');
        }
        $status = (string) $job['status'];
        if (!in_array($status, ['pending', 'running'], true)) {
            throw new RuntimeException('Only pending or running jobs can be cancelled.');
        }

        return $this->markFailed(
            $id,
            (string) $job['domain'],
            (string) ($job['stdout'] ?? ''),
            rtrim((string) ($job['stderr'] ?? '') . "\n" . $reason) . "\n",
            130
        );
    }

    public function processNext(): ?array
    {
        $this->failStaleRunningJobs();
        $this->reclassifySkippedRenewals();

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->query(
                "SELECT * FROM jobs WHERE status = 'pending' ORDER BY id ASC LIMIT 1 FOR UPDATE"
            );
            $job = $stmt->fetch();
            if ($job === false) {
                $pdo->commit();
                return null;
            }

            $upd = $pdo->prepare(
                "UPDATE jobs SET status = 'running', started_at = ? WHERE id = ?"
            );
            $upd->execute([now_utc(), $job['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $domain = (string) $job['domain'];
        $updDomain = $pdo->prepare('UPDATE domains SET status = ?, updated_at = ? WHERE domain = ?');
        $updDomain->execute(['job-running', now_utc(), $domain]);

        try {
            return $this->runJob($job);
        } catch (Throwable $e) {
            return $this->markFailed(
                (int) $job['id'],
                $domain,
                '',
                'Worker error: ' . $e->getMessage() . "\n",
                1
            );
        }
    }

    /**
     * Mark running jobs that exceeded max runtime (or were abandoned) as failed
     * so a later worker pass can accept a retry enqueue.
     */
    public function failStaleRunningJobs(): int
    {
        $maxRuntime = (int) config_get('jobs.max_runtime_seconds', 600);
        if ($maxRuntime < 60) {
            $maxRuntime = 60;
        }
        // Grace beyond PHP time limit so a still-running acme.sh is not cut too early.
        $staleAfter = $maxRuntime + 60;

        $pdo = Database::pdo();
        // INTERVAL placeholders are unreliable on some MariaDB builds; value is an int.
        $stmt = $pdo->query(
            "SELECT id, domain FROM jobs
             WHERE status = 'running'
               AND started_at IS NOT NULL
               AND started_at < (UTC_TIMESTAMP() - INTERVAL {$staleAfter} SECOND)"
        );
        $rows = $stmt->fetchAll();
        $count = 0;
        foreach ($rows as $row) {
            $this->markFailed(
                (int) $row['id'],
                (string) $row['domain'],
                '',
                "Job timed out or worker interrupted after {$staleAfter}s still marked running.\n",
                124
            );
            $count++;
        }
        return $count;
    }

    /** @param array<string,mixed> $job */
    private function runJob(array $job): array
    {
        $jobId = (int) $job['id'];
        $action = (string) $job['action'];
        $domain = (string) $job['domain'];
        $payload = [];
        if (!empty($job['payload'])) {
            $decoded = json_decode((string) $job['payload'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        // If PHP fatals / hits max_runtime mid-run, leave a failed row instead of forever-running.
        register_shutdown_function(function () use ($jobId, $domain): void {
            try {
                $stmt = Database::pdo()->prepare('SELECT status FROM jobs WHERE id = ? LIMIT 1');
                $stmt->execute([$jobId]);
                $row = $stmt->fetch();
                if ($row === false || ($row['status'] ?? '') !== 'running') {
                    return;
                }
                $err = error_get_last();
                $detail = is_array($err) ? (string) ($err['message'] ?? 'unknown error') : 'unknown error';
                $this->markFailed(
                    $jobId,
                    $domain,
                    '',
                    "Worker process ended while job was still running: {$detail}\n",
                    1
                );
            } catch (Throwable) {
                // Best-effort; failStaleRunningJobs() is the backstop.
            }
        });

        if ($action === 'remove') {
            try {
                $payload = $this->normalizeRemovePayload($payload);
            } catch (Throwable $e) {
                return $this->markFailed($jobId, $domain, '', $e->getMessage() . "\n", 1);
            }
            return $this->runRemoveJob($jobId, $domain, $payload);
        }

        $commands = $this->buildCommands($action, $domain, $payload);
        $stdout = '';
        $stderr = '';
        $exitCode = 0;
        $renewSoftSkip = false;

        foreach ($commands as $index => $cmd) {
            $stdout .= '$ ' . $cmd . "\n";
            $descriptors = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($cmd, $descriptors, $pipes, null, null);
            if (!is_resource($proc)) {
                $stderr .= "Failed to start process.\n";
                $exitCode = 127;
                break;
            }

            $out = stream_get_contents($pipes[1]) ?: '';
            $err = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);

            $stdout .= $out;
            $stderr .= $err;
            $exitCode = $code;
            if ($code === 0) {
                continue;
            }
            // Renew not-due: still run the follow-up deploy so cPanel can catch up.
            if ($action === 'renew' && $index === 0 && job_is_renew_skip($out)) {
                $renewSoftSkip = true;
                continue;
            }
            break;
        }

        // Renew not-due leaves a non-zero from the first step; success means deploy finished OK
        // (exitCode 0) after continue. Pure skip with no follow-up is still success.
        if ($exitCode === 0 || ($renewSoftSkip && count($commands) === 1)) {
            return $this->markSuccess($jobId, $domain, $stdout, $stderr, 0);
        }

        return $this->markFailed($jobId, $domain, $stdout, $stderr, $exitCode);
    }

    /**
     * Correct historical "failed" renews that were only acme.sh skip-not-due.
     */
    public function reclassifySkippedRenewals(): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            "SELECT id, stdout FROM jobs WHERE status = 'failed' AND action = 'renew'"
        );
        $count = 0;
        $upd = $pdo->prepare(
            "UPDATE jobs SET status = 'success' WHERE id = ? AND status = 'failed'"
        );
        while ($row = $stmt->fetch()) {
            if (!job_is_renew_skip(isset($row['stdout']) ? (string) $row['stdout'] : null)) {
                continue;
            }
            $upd->execute([(int) $row['id']]);
            $count++;
        }
        return $count;
    }

    /** @return array{id:int,status:string,exit_code:int,domain:string,action:string} */
    private function markSuccess(int $jobId, string $domain, string $stdout, string $stderr, int $exitCode): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "UPDATE jobs SET status = 'success', stdout = ?, stderr = ?, exit_code = ?, finished_at = ?
             WHERE id = ? AND status = 'running'"
        );
        $stmt->execute([$stdout, $stderr, $exitCode, now_utc(), $jobId]);

        $this->writeJobLog($jobId, $stdout, $stderr);

        try {
            (new SyncService())->sync();
        } catch (Throwable) {
            // ignore sync errors after job
        }

        $job = $this->find($jobId);
        return [
            'id' => $jobId,
            'status' => 'success',
            'exit_code' => $exitCode,
            'domain' => $domain,
            'action' => (string) ($job['action'] ?? ''),
        ];
    }

    /** @return array{id:int,status:string,exit_code:int,domain:string,action:string} */
    private function markFailed(
        int $jobId,
        string $domain,
        string $stdout,
        string $stderr,
        int $exitCode
    ): array {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "UPDATE jobs SET status = 'failed', stdout = ?, stderr = ?, exit_code = ?, finished_at = ?
             WHERE id = ? AND status IN ('pending','running')"
        );
        $stmt->execute([$stdout, $stderr, $exitCode, now_utc(), $jobId]);

        $this->writeJobLog($jobId, $stdout, $stderr);

        $upd = $pdo->prepare('UPDATE domains SET status = ?, updated_at = ? WHERE domain = ?');
        $upd->execute(['job-failed', now_utc(), $domain]);

        $job = $this->find($jobId);
        return [
            'id' => $jobId,
            'status' => 'failed',
            'exit_code' => $exitCode,
            'domain' => $domain,
            'action' => (string) ($job['action'] ?? ''),
        ];
    }

    private function writeJobLog(int $jobId, string $stdout, string $stderr): void
    {
        $logDir = (string) config_get('jobs.log_dir', dirname(__DIR__) . '/storage/logs');
        if (is_dir($logDir) && is_writable($logDir)) {
            file_put_contents(
                $logDir . '/job-' . $jobId . '.log',
                "STDOUT\n$stdout\n\nSTDERR\n$stderr\n"
            );
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<string>
     */
    private function buildCommands(string $action, string $domain, array $payload): array
    {
        $bin = (string) config_get('paths.acme_bin');
        $home = (string) config_get('paths.acme_home');
        $cpanelUser = (string) config_get('paths.cpanel_user');

        if ($bin === '' || $home === '') {
            throw new RuntimeException('acme paths not configured.');
        }

        $deploy = escapeshellarg($bin)
            . ' --deploy --deploy-hook cpanel_uapi --domain ' . escapeshellarg($domain)
            . ' --home ' . escapeshellarg($home);

        if ($action === 'deploy') {
            return [$deploy];
        }

        if ($action === 'renew') {
            return [
                escapeshellarg($bin)
                . ' --renew -d ' . escapeshellarg($domain)
                . ' --home ' . escapeshellarg($home),
                $deploy,
            ];
        }

        if ($action === 'issue') {
            $webroot = (string) ($payload['webroot'] ?? '');
            if ($webroot === '' || !str_starts_with($webroot, '/home/')) {
                throw new RuntimeException('Issue requires a valid webroot under /home/.');
            }
            // Prevent path escape / injection via spaces handled by escapeshellarg
            if (!preg_match('#^/home/[a-zA-Z0-9_.-]+(/[a-zA-Z0-9_.-]+)*$#', $webroot)) {
                throw new RuntimeException('Webroot path rejected.');
            }
            if ($cpanelUser !== '' && !str_starts_with($webroot, '/home/' . $cpanelUser . '/')) {
                throw new RuntimeException('Webroot must be under this cPanel user home.');
            }

            $includeWww = !empty($payload['include_www']);
            $issue = escapeshellarg($bin)
                . ' --issue -d ' . escapeshellarg($domain);
            if ($includeWww) {
                $issue .= ' -d ' . escapeshellarg('www.' . $domain);
            }
            $issue .= ' -w ' . escapeshellarg($webroot)
                . ' --home ' . escapeshellarg($home);

            return [$issue, $deploy];
        }

        throw new InvalidArgumentException('Unsupported action.');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{from_acme:bool,purge_acme_files:bool,from_cpanel:bool}
     */
    private function normalizeRemovePayload(array $payload): array
    {
        $normalized = [
            'from_acme' => !empty($payload['from_acme']),
            'purge_acme_files' => !empty($payload['purge_acme_files']),
            'from_cpanel' => !empty($payload['from_cpanel']),
        ];
        if (!$normalized['from_acme'] && !$normalized['purge_acme_files'] && !$normalized['from_cpanel']) {
            throw new InvalidArgumentException(
                'Cleanup requires at least one of: remove from acme.sh, purge files, or uninstall cPanel SSL.'
            );
        }
        return $normalized;
    }

    /**
     * @param array{from_acme:bool,purge_acme_files:bool,from_cpanel:bool} $payload
     * @return array{id:int,status:string,exit_code:int,domain:string,action:string}
     */
    private function runRemoveJob(int $jobId, string $domain, array $payload): array
    {
        $stdout = '';
        $stderr = '';
        $acme = new AcmeAdapter();
        $cpanel = new CpanelAdapter();

        try {
            if (!empty($payload['from_cpanel'])) {
                $stdout .= "# Uninstall SSL from cPanel (uapi SSL delete_ssl)\n";
                $msg = $cpanel->deleteSsl($domain);
                $stdout .= $msg . "\n";
            }

            if (!empty($payload['from_acme'])) {
                $stdout .= "# Remove from acme.sh renewal list\n";
                $result = $acme->removeFromList($domain);
                $stdout .= $result['stdout'];
                $stderr .= $result['stderr'];
                if ($result['soft_miss']) {
                    $stdout .= "Note: domain was not on the acme.sh renewal list (already gone).\n";
                } elseif ($result['removed']) {
                    $stdout .= "Removed from acme.sh renewal list.\n";
                }
            }

            if (!empty($payload['purge_acme_files'])) {
                $stdout .= "# Purge leftover acme.sh certificate directories\n";
                $dirs = $acme->purgeDomainFiles($domain);
                if ($dirs === []) {
                    $stdout .= "No acme.sh directories found for {$domain} (already clean).\n";
                } else {
                    $stdout .= 'Deleted: ' . implode(', ', $dirs) . "\n";
                }
            }
        } catch (Throwable $e) {
            $stderr .= $e->getMessage() . "\n";
            return $this->markFailed($jobId, $domain, $stdout, $stderr, 1);
        }

        return $this->markSuccess($jobId, $domain, $stdout, $stderr, 0);
    }
}
