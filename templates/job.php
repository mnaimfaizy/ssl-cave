<?php /** @var array<string,mixed> $job */ ?>
<?php
$canCancel = in_array((string) $job['status'], ['pending', 'running'], true);
$stdout = isset($job['stdout']) ? (string) $job['stdout'] : '';
$isSkip = (string) $job['action'] === 'renew' && job_is_renew_skip($stdout);
$displayStatus = ((string) $job['status'] === 'failed' && $isSkip) ? 'success' : (string) $job['status'];
?>
<div class="section-head">
    <div>
        <p class="muted"><a href="/?r=jobs">← Jobs</a> · <a href="/?r=domain&amp;d=<?= e(urlencode((string) $job['domain'])) ?>"><?= e((string) $job['domain']) ?></a></p>
        <h1>Job #<?= (int) $job['id'] ?></h1>
    </div>
    <?php if ($canCancel): ?>
    <form method="post" action="/?r=job&amp;id=<?= (int) $job['id'] ?>" onsubmit="return confirm('Mark this job as failed so you can enqueue again?');">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="secondary outline">Mark failed</button>
    </form>
    <?php endif; ?>
</div>
<?php if ($isSkip): ?>
    <p class="flash ok">acme.sh skipped renewal because the certificate is not due yet. This is not a real failure<?= (string) $job['status'] === 'failed' ? ' — status will correct on the next worker run' : '' ?>.</p>
<?php endif; ?>
<figure>
<table>
    <tbody>
    <tr><th>Domain</th><td class="mono"><a href="/?r=domain&amp;d=<?= e(urlencode((string) $job['domain'])) ?>"><?= e((string) $job['domain']) ?></a></td></tr>
    <tr><th>Action</th><td><?= e((string) $job['action']) ?></td></tr>
    <tr><th>Status</th><td><span class="status status-job-<?= e($displayStatus) ?>"><?= e(job_status_label($displayStatus)) ?></span></td></tr>
    <tr><th>Summary</th><td><?= e(job_summary_line($stdout, isset($job['stderr']) ? (string) $job['stderr'] : null)) ?></td></tr>
    <tr><th>Exit code</th><td><?= e((string) ($job['exit_code'] ?? '—')) ?></td></tr>
    <tr><th>Created</th><td><?= e((string) $job['created_at']) ?></td></tr>
    <tr><th>Started</th><td><?= e((string) ($job['started_at'] ?? '—')) ?></td></tr>
    <tr><th>Finished</th><td><?= e((string) ($job['finished_at'] ?? '—')) ?></td></tr>
    </tbody>
</table>
</figure>
<?php if ($canCancel): ?>
<p class="muted">Stuck pending usually means the <span class="mono">bin/worker.php</span> cron is missing or not running. Mark failed, fix cron, then enqueue again.</p>
<?php endif; ?>
<?php if (!empty($job['payload'])): ?>
<h2>Payload</h2>
<pre class="log"><?= e((string) $job['payload']) ?></pre>
<?php endif; ?>
<h2>stdout</h2>
<pre class="log"><?= e($stdout) ?></pre>
<h2>stderr</h2>
<pre class="log"><?= e((string) ($job['stderr'] ?? '')) ?></pre>
