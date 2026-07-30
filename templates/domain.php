<?php
/** @var array<string,mixed> $domain */
/** @var list<array<string,mixed>> $jobs */
$name = (string) $domain['domain'];
$inAcme = (int) $domain['in_acme'] === 1;
$inCpanel = (int) $domain['in_cpanel'] === 1;
$status = (string) $domain['status'];
$deployMissing = (int) $domain['deploy_hook'] !== 1;
$showDeploy = $inAcme && (
    $status === 'drift'
    || $status === 'acme-only'
    || $deployMissing
);
$inventoryOnly = !$inAcme && !$inCpanel;
$defaultFromAcme = $inAcme || $status === 'acme-only';
$defaultPurge = $defaultFromAcme;
$defaultFromCpanel = $inCpanel;
?>
<div class="section-head">
    <div>
        <p class="muted"><a href="/">← Inventory</a></p>
        <h1 class="mono"><?= e($name) ?></h1>
    </div>
    <div class="actions-inline">
        <?php if ($inAcme): ?>
        <form method="post" action="/?r=renew">
            <?= Csrf::field() ?>
            <input type="hidden" name="domain" value="<?= e($name) ?>">
            <button type="submit">Enqueue renew</button>
        </form>
        <?php if ($showDeploy): ?>
        <form method="post" action="/?r=deploy">
            <?= Csrf::field() ?>
            <input type="hidden" name="domain" value="<?= e($name) ?>">
            <button type="submit" class="outline">Deploy to cPanel</button>
        </form>
        <?php endif; ?>
        <?php elseif (!$inventoryOnly): ?>
        <a href="/?r=issue&amp;d=<?= e(urlencode($name)) ?>" role="button">Issue certificate</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$inAcme && !$inventoryOnly): ?>
<p class="muted">This host is not in acme.sh yet<?= $inCpanel ? ' (cPanel/AutoSSL only)' : '' ?>. Use <strong>Issue certificate</strong> to create one with acme.sh and deploy via <span class="mono">cpanel_uapi</span>. Renew appears after a successful issue.</p>
<?php elseif ($status === 'drift' || $status === 'acme-only' || $deployMissing): ?>
<p class="muted">
    <?php if ($status === 'drift'): ?>
        <strong>Drift</strong> means acme.sh and cPanel disagree on the installed cert expiry, or the <span class="mono">cpanel_uapi</span> deploy hook is missing.
    <?php elseif ($status === 'acme-only'): ?>
        This cert is in acme.sh but not installed in cPanel — often a typo, failed issue, or leftover after a rename.
    <?php else: ?>
        The <span class="mono">cpanel_uapi</span> deploy hook is missing — renewals may not land in cPanel.
    <?php endif; ?>
    <?php if ($inAcme && $showDeploy): ?>
        Use <strong>Deploy to cPanel</strong> to push the acme.sh cert, or <strong>Cleanup</strong> below to remove a bad entry.
    <?php endif; ?>
</p>
<?php endif; ?>

<figure>
<table>
    <tbody>
    <tr><th>Status</th><td><span class="status status-<?= e($status) ?>"><?= e(status_label($status)) ?></span></td></tr>
    <tr><th>cPanel</th><td><?= $inCpanel ? 'yes' : 'no' ?> · expiry <?= e((string) ($domain['cpanel_expiry'] ?? '—')) ?></td></tr>
    <tr><th>acme.sh</th><td><?= $inAcme ? 'yes' : 'no' ?> · expiry <?= e((string) ($domain['acme_expiry'] ?? '—')) ?></td></tr>
    <tr><th>Deploy hook</th><td class="mono"><?= (int) $domain['deploy_hook'] === 1 ? 'cpanel_uapi' : 'missing' ?></td></tr>
    <tr><th>Webroot</th><td class="mono"><?= e((string) ($domain['webroot'] ?? '—')) ?></td></tr>
    <tr><th>Last synced</th><td><?= e((string) ($domain['last_synced_at'] ?? '—')) ?> UTC</td></tr>
    </tbody>
</table>
</figure>

<form method="post" action="/?r=notes">
    <?= Csrf::field() ?>
    <input type="hidden" name="domain" value="<?= e($name) ?>">
    <label>
        Notes
        <textarea name="notes" rows="4"><?= e((string) ($domain['notes'] ?? '')) ?></textarea>
    </label>
    <button type="submit" class="outline">Save notes</button>
</form>

<section class="danger-zone">
    <h2>Cleanup / remove</h2>
    <p class="muted">
        Use this for typos, abandoned issues, or faulty leftovers.
        Cleanup runs via the worker (never inside the browser request).
        Does <strong>not</strong> revoke with the CA — it only stops renewals and/or uninstalls local coverage.
    </p>

    <?php if ($inventoryOnly): ?>
    <form method="post" action="/?r=cleanup" class="cleanup-form" onsubmit="return confirm('Remove <?= e($name) ?> from portal inventory only?');">
        <?= Csrf::field() ?>
        <input type="hidden" name="domain" value="<?= e($name) ?>">
        <input type="hidden" name="inventory_only" value="1">
        <label>
            Type <span class="mono"><?= e($name) ?></span> to confirm
            <input type="text" name="confirm_domain" required autocomplete="off" spellcheck="false" placeholder="<?= e($name) ?>">
        </label>
        <button type="submit" class="contrast">Remove from inventory</button>
    </form>
    <?php else: ?>
    <form method="post" action="/?r=cleanup" class="cleanup-form" onsubmit="return confirm('Queue cleanup for <?= e($name) ?>? This can uninstall SSL and delete acme.sh files.');">
        <?= Csrf::field() ?>
        <input type="hidden" name="domain" value="<?= e($name) ?>">
        <fieldset>
            <legend>Sources to clean</legend>
            <label>
                <input type="checkbox" name="from_acme" value="1"<?= $defaultFromAcme ? ' checked' : '' ?>>
                Remove from acme.sh renewal list (<span class="mono">--remove</span>)
            </label>
            <label>
                <input type="checkbox" name="purge_acme_files" value="1"<?= $defaultPurge ? ' checked' : '' ?>>
                Delete leftover acme.sh directories (<span class="mono"><?= e($name) ?></span> / <span class="mono"><?= e($name) ?>_ecc</span>)
            </label>
            <label>
                <input type="checkbox" name="from_cpanel" value="1"<?= $defaultFromCpanel ? ' checked' : '' ?>>
                Uninstall SSL from cPanel (<span class="mono">uapi SSL delete_ssl</span>)
            </label>
        </fieldset>
        <label>
            Type <span class="mono"><?= e($name) ?></span> to confirm
            <input type="text" name="confirm_domain" required autocomplete="off" spellcheck="false" placeholder="<?= e($name) ?>">
        </label>
        <button type="submit" class="contrast">Enqueue cleanup</button>
    </form>
    <?php endif; ?>
</section>

<h2>Recent jobs</h2>
<p class="muted">Click a job ID for full stdout/stderr (acme.sh errors).</p>
<?php if ($jobs === []): ?>
    <p class="muted">No jobs for this domain.</p>
<?php else: ?>
<figure>
<table>
    <thead>
    <tr><th>ID</th><th>Action</th><th>Status</th><th>Summary</th><th>Finished</th></tr>
    </thead>
    <tbody>
    <?php foreach ($jobs as $job): ?>
        <tr>
            <td><a href="/?r=job&amp;id=<?= (int) $job['id'] ?>">#<?= (int) $job['id'] ?></a></td>
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
