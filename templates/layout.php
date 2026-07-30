<?php
/** @var string $content */
/** @var string|null $title */
$appName = (string) config_get('app_name', 'SSL Cave');
$pageTitle = isset($title) ? ($title . ' · ' . $appName) : $appName;
$pendingJobs = 0;
if (Auth::check()) {
    try {
        $pendingJobs = (new JobQueue())->pendingCount();
    } catch (Throwable) {
        $pendingJobs = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="container">
<?php if (Auth::check()): ?>
    <header class="topbar">
        <a class="brand" href="/"><?= e($appName) ?></a>
        <nav>
            <form method="post" action="/?r=sync">
                <?= Csrf::field() ?>
                <button type="submit" class="outline">Sync</button>
            </form>
            <a href="/?r=jobs" role="button" class="outline secondary">
                Jobs <?php if ($pendingJobs > 0): ?><span class="badge"><?= (int) $pendingJobs ?></span><?php endif; ?>
            </a>
            <a href="/?r=issue" role="button" class="outline secondary">Issue</a>
            <a href="/?r=settings" role="button" class="outline secondary">Settings</a>
            <form method="post" action="/?r=logout">
                <?= Csrf::field() ?>
                <button type="submit" class="secondary outline">Logout</button>
            </form>
        </nav>
    </header>
<?php endif; ?>

<?php foreach (consume_flash() as $msg): ?>
    <div class="flash <?= e($msg['type']) ?>"><?= e($msg['message']) ?></div>
<?php endforeach; ?>

<?= $content ?>
</main>
</body>
</html>
