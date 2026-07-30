<?php
/** @var list<array<string,mixed>> $domains */
/** @var list<array<string,mixed>> $attention */
/** @var array<string,array<string,mixed>> $lastJobs */
/** @var string|null $lastSync */
?>
<div class="section-head">
    <div>
        <h1>Certificates</h1>
        <p class="muted">Last sync: <?= $lastSync ? e($lastSync) . ' UTC' : 'never' ?></p>
    </div>
</div>

<?php if ($attention !== []): ?>
<section class="needs-attention">
    <h2>Needs attention</h2>
    <ul>
        <?php foreach ($attention as $row): ?>
            <li>
                <a class="mono" href="/?r=domain&amp;d=<?= e(urlencode((string) $row['domain'])) ?>"><?= e((string) $row['domain']) ?></a>
                — <span class="status status-<?= e((string) $row['status']) ?>"><?= e(status_label((string) $row['status'])) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($domains === []): ?>
    <p class="muted">No domains yet. Run <strong>Sync</strong> to import from acme.sh and cPanel.</p>
<?php else: ?>
<figure>
<table class="inventory">
    <thead>
    <tr>
        <th>Domain</th>
        <th>cPanel expiry</th>
        <th>acme.sh expiry</th>
        <th>Deploy</th>
        <th>Status</th>
        <th>Last job</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($domains as $row): ?>
        <?php
        $domainName = (string) $row['domain'];
        $last = $lastJobs[$domainName] ?? null;
        ?>
        <tr>
            <td class="domain">
                <a href="/?r=domain&amp;d=<?= e(urlencode($domainName)) ?>"><?= e($domainName) ?></a>
            </td>
            <td><?= e((string) ($row['cpanel_expiry'] ?? '—')) ?></td>
            <td><?= e((string) ($row['acme_expiry'] ?? '—')) ?></td>
            <td><?= (int) $row['deploy_hook'] === 1 ? 'cpanel_uapi' : '—' ?></td>
            <td><span class="status status-<?= e((string) $row['status']) ?>"><?= e(status_label((string) $row['status'])) ?></span></td>
            <td>
                <?php if ($last === null): ?>
                    <span class="muted">—</span>
                <?php else: ?>
                    <a href="/?r=job&amp;id=<?= (int) $last['id'] ?>" class="job-last-link">
                        <span class="status status-job-<?= e((string) $last['status']) ?>"><?= e(job_status_label((string) $last['status'])) ?> · <?= e((string) $last['action']) ?></span>
                    </a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</figure>
<?php endif; ?>
