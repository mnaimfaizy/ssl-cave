<?php

declare(strict_types=1);

final class SyncService
{
    public function __construct(
        private readonly AcmeAdapter $acme = new AcmeAdapter(),
        private readonly CpanelAdapter $cpanel = new CpanelAdapter(),
    ) {
    }

    /** @return array{domains:int,pruned:int,errors:list<string>} */
    public function sync(): array
    {
        $errors = [];
        $acmeCerts = [];
        $cpanelHosts = [];
        $acmeOk = false;
        $cpanelOk = false;

        try {
            $acmeCerts = $this->acme->listCertificates();
            $acmeOk = true;
        } catch (Throwable $e) {
            $errors[] = 'acme: ' . $e->getMessage();
        }

        try {
            $cpanelHosts = $this->cpanel->installedHosts();
            $cpanelOk = true;
        } catch (Throwable $e) {
            $errors[] = 'cpanel: ' . $e->getMessage();
        }

        $byDomain = [];

        foreach ($cpanelHosts as $host) {
            $d = $host['domain'];
            $byDomain[$d] = [
                'domain' => $d,
                'cpanel_expiry' => $host['expiry'],
                'acme_expiry' => null,
                'deploy_hook' => 0,
                'webroot' => null,
                'in_cpanel' => 1,
                'in_acme' => 0,
            ];
        }

        foreach ($acmeCerts as $cert) {
            $d = $cert['domain'];
            if (!isset($byDomain[$d])) {
                $byDomain[$d] = [
                    'domain' => $d,
                    'cpanel_expiry' => null,
                    'acme_expiry' => null,
                    'deploy_hook' => 0,
                    'webroot' => null,
                    'in_cpanel' => 0,
                    'in_acme' => 0,
                ];
            }
            $byDomain[$d]['acme_expiry'] = $cert['expiry'];
            $byDomain[$d]['deploy_hook'] = $cert['deploy_hook'] ? 1 : 0;
            $byDomain[$d]['webroot'] = $cert['webroot'];
            $byDomain[$d]['in_acme'] = 1;
        }

        $pdo = Database::pdo();
        $now = now_utc();
        $seen = [];

        foreach ($byDomain as $row) {
            $status = $this->computeStatus($row);
            $seen[] = $row['domain'];

            $stmt = $pdo->prepare(
                'INSERT INTO domains
                    (domain, cpanel_expiry, acme_expiry, deploy_hook, webroot, status,
                     in_cpanel, in_acme, last_synced_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    cpanel_expiry = VALUES(cpanel_expiry),
                    acme_expiry = VALUES(acme_expiry),
                    deploy_hook = VALUES(deploy_hook),
                    webroot = COALESCE(VALUES(webroot), webroot),
                    status = VALUES(status),
                    in_cpanel = VALUES(in_cpanel),
                    in_acme = VALUES(in_acme),
                    last_synced_at = VALUES(last_synced_at),
                    updated_at = VALUES(updated_at)'
            );
            $stmt->execute([
                $row['domain'],
                $row['cpanel_expiry'],
                $row['acme_expiry'],
                $row['deploy_hook'],
                $row['webroot'],
                $status,
                $row['in_cpanel'],
                $row['in_acme'],
                $now,
                $now,
                $now,
            ]);
        }

        // Only prune when both sources succeeded — otherwise acme-only / cpanel-only
        // rows would disappear whenever one side fails transiently.
        $pruned = 0;
        if ($acmeOk && $cpanelOk) {
            $pruned = $this->pruneMissing($seen);
        }

        // Refresh job overlay statuses
        $this->applyJobOverlays();

        Database::setSetting('last_sync_at', $now);

        return ['domains' => count($seen), 'pruned' => $pruned, 'errors' => $errors];
    }

    /**
     * Drop inventory rows no longer present in either live source.
     * Keeps rows that still have a pending/running job (e.g. mid-cleanup).
     *
     * @param list<string> $seen
     */
    private function pruneMissing(array $seen): int
    {
        $pdo = Database::pdo();
        $seenMap = array_fill_keys($seen, true);
        $rows = $pdo->query('SELECT domain FROM domains')->fetchAll();
        $pruned = 0;

        foreach ($rows as $row) {
            $domain = (string) $row['domain'];
            if (isset($seenMap[$domain])) {
                continue;
            }

            $active = $pdo->prepare(
                "SELECT id FROM jobs WHERE domain = ? AND status IN ('pending','running') LIMIT 1"
            );
            $active->execute([$domain]);
            if ($active->fetch() !== false) {
                continue;
            }

            $del = $pdo->prepare('DELETE FROM domains WHERE domain = ?');
            $del->execute([$domain]);
            $pruned += $del->rowCount() > 0 ? 1 : 0;
        }

        return $pruned;
    }

    /** @param array{domain:string,cpanel_expiry:?string,acme_expiry:?string,deploy_hook:int,in_cpanel:int,in_acme:int} $row */
    public function computeStatus(array $row): string
    {
        $inC = (int) $row['in_cpanel'] === 1;
        $inA = (int) $row['in_acme'] === 1;

        if ($inC && !$inA) {
            return 'cpanel-only';
        }
        if ($inA && !$inC) {
            return 'acme-only';
        }

        $cExp = $row['cpanel_expiry'] ?? null;
        $aExp = $row['acme_expiry'] ?? null;

        if ($cExp && $aExp && $cExp !== $aExp) {
            return 'drift';
        }

        // Managed by acme but missing deploy hook → treat as drift risk
        if ($inA && (int) $row['deploy_hook'] !== 1) {
            return 'drift';
        }

        $soonest = null;
        foreach ([$cExp, $aExp] as $exp) {
            $days = days_until($exp);
            if ($days !== null && ($soonest === null || $days < $soonest)) {
                $soonest = $days;
            }
        }
        if ($soonest !== null && $soonest <= 30) {
            return 'expiring';
        }

        return 'ok';
    }

    public function applyJobOverlays(): void
    {
        $pdo = Database::pdo();

        // Reset job overlays back to computed base first by re-reading non-job statuses
        // Only overlay domains with active/failed recent jobs
        $stmt = $pdo->query(
            "SELECT domain, status, action, stdout FROM jobs
             WHERE status IN ('pending','running','failed')
             AND created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)
             ORDER BY id DESC"
        );
        $seen = [];
        while ($job = $stmt->fetch()) {
            $domain = (string) $job['domain'];
            if (isset($seen[$domain])) {
                continue;
            }
            $seen[$domain] = true;
            // Skip-not-due renews are not real failures for inventory overlays.
            if (
                (string) $job['status'] === 'failed'
                && (string) $job['action'] === 'renew'
                && job_is_renew_skip(isset($job['stdout']) ? (string) $job['stdout'] : null)
            ) {
                continue;
            }
            $overlay = match ($job['status']) {
                'pending' => 'job-pending',
                'running' => 'job-running',
                'failed' => 'job-failed',
                default => null,
            };
            if ($overlay === null) {
                continue;
            }
            $upd = $pdo->prepare('UPDATE domains SET status = ?, updated_at = ? WHERE domain = ?');
            $upd->execute([$overlay, now_utc(), $domain]);
        }
    }
}
