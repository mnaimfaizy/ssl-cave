<?php
/** @var string $alertEmail */
/** @var string|null $lastSync */
?>
<div class="section-head">
    <h1>Settings</h1>
</div>

<form method="post" action="/?r=settings">
    <?= Csrf::field() ?>
    <label>
        Alert email
        <input type="email" name="alert_email" value="<?= e($alertEmail) ?>" required>
    </label>
    <p class="muted">Used for expiry (30 / 14 / 7 days), drift, and job-failure alerts. Delivery uses PHP <span class="mono">mail()</span> with <span class="mono">alerts.from</span> from <span class="mono">config.php</span> — that From address should be a mailbox on this cPanel account (e.g. <span class="mono">ssl-cave@example.com</span>), not <span class="mono">@localhost</span>.</p>
    <button type="submit">Save</button>
</form>

<hr>
<p class="muted">Last sync: <?= $lastSync ? e($lastSync) . ' UTC' : 'never' ?></p>
<p class="muted">Routine renewals remain on existing <span class="mono">acme.sh --cron</span>. This portal only queues on-demand issue/renew.</p>
