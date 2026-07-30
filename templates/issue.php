<?php
/** @var string $cpanelUser */
/** @var string $prefillDomain */
/** @var string $suggestedWebroot */
/** @var bool $defaultIncludeWww */
$defaultWebrootHint = $cpanelUser !== '' ? "/home/{$cpanelUser}/example.com" : '/home/USER/example.com';
$webrootValue = $suggestedWebroot !== '' ? $suggestedWebroot : '';
?>
<div class="section-head">
    <h1>Issue certificate</h1>
</div>
<p class="muted">Queues <span class="mono">acme.sh --issue</span> then <span class="mono">--deploy --deploy-hook cpanel_uapi</span>. Worker runs it via cron. Use this for <strong>cPanel only</strong> domains that are not yet managed by acme.sh.</p>

<form method="post" action="/?r=issue">
    <?= Csrf::field() ?>
    <label>
        Primary domain
        <input class="mono" type="text" name="domain" value="<?= e($prefillDomain) ?>" placeholder="example.com" required pattern="[a-zA-Z0-9.-]+">
    </label>
    <label>
        <input type="checkbox" name="include_www" value="1"<?= $defaultIncludeWww ? ' checked' : '' ?>>
        Also include <span class="mono">www.</span> subdomain
    </label>
    <label>
        Webroot
        <input class="mono" type="text" name="webroot" value="<?= e($webrootValue) ?>" placeholder="<?= e($defaultWebrootHint) ?>" required>
    </label>
    <p class="muted">Must be the site document root (HTTP-01 writes to <span class="mono">.well-known/acme-challenge/</span> under it). On Namecheap this is usually <span class="mono">/home/USER/domain.tld</span>.</p>
    <button type="submit">Enqueue issue</button>
</form>
