<?php
/** @var list<array<string,mixed>> $jobs */
/** @var ?string $workerLastRunAt */
/** @var int $pendingJobs */
$workerStale = true;
$workerAgeMinutes = null;
if (is_string($workerLastRunAt) && $workerLastRunAt !== '') {
    $ts = strtotime($workerLastRunAt . ' UTC');
    if ($ts !== false) {
        $workerAgeMinutes = (int) floor((time() - $ts) / 60);
        $workerStale = $workerAgeMinutes > 15;
    }
}
?>
<div class="section-head">
    <h1>Jobs</h1>
</div>
<p class="muted">Issue/renew run via cron worker — never inside the HTTP request. Open a job for full stdout/stderr.</p>
<?php if ($workerLastRunAt === null || $workerLastRunAt === ''): ?>
    <p class="flash error">Worker has never reported a run. Add the <span class="mono">bin/worker.php</span> cron (every 5 minutes) or jobs will stay pending forever.</p>
<?php elseif ($workerStale): ?>
    <p class="flash error">Worker last ran <?= e((string) $workerLastRunAt) ?> UTC
        <?php if ($workerAgeMinutes !== null): ?>(<?= (int) $workerAgeMinutes ?> min ago)<?php endif; ?>.
        Pending jobs will not advance until cron runs <span class="mono">bin/worker.php</span>.</p>
<?php else: ?>
    <p class="muted">Worker last ran <?= e((string) $workerLastRunAt) ?> UTC.</p>
<?php endif; ?>
<?php if ($pendingJobs > 0 && ($workerLastRunAt === null || $workerLastRunAt === '' || $workerStale)): ?>
    <p class="muted">You can also run once via SSH/Terminal:
        <span class="mono">/usr/local/bin/php /home/USER/&lt;docroot&gt;/bin/worker.php</span>
    </p>
<?php endif; ?>
<?php if ($jobs === []): ?>
    <p class="muted">No jobs yet.</p>
<?php else: ?>
<figure>
<table>
    <thead>
    <tr><th>ID</th><th>Domain</th><th>Action</th><th>Status</th><th>Summary</th><th>Finished</th></tr>
    </thead>
    <tbody>
    <?php foreach ($jobs as $job): ?>
        <tr>
            <td><a href="/?r=job&amp;id=<?= (int) $job['id'] ?>">#<?= (int) $job['id'] ?></a></td>
            <td class="mono"><a href="/?r=domain&amp;d=<?= e(urlencode((string) $job['domain'])) ?>"><?= e((string) $job['domain']) ?></a></td>
            <td><?= e((string) $job['action']) ?></td>
            <td><span class="status status-job-<?= e((string) $job['status']) ?>"><?= e(job_status_label((string) $job['status'])) ?></span></td>
            <td class="job-summary"><?= e(job_summary_line(
                isset($job['stdout']) ? (string) $job['stdout'] : null,
                isset($job['stderr']) ? (string) $job['stderr'] : null
            )) ?></td>
            <td><?= e((string) ($job['finished_at'] ?? '—')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</figure>
<?php endif; ?>
