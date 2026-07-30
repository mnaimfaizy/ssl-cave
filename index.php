<?php

declare(strict_types=1);

/**
 * Front controller — lives in the subdomain document root
 * (e.g. /home/<user>/ssl-cave.example.com/).
 *
 * Do NOT point Document Root at a nested public/ folder on cPanel shared hosting:
 * AutoSSL / acme.sh HTTP-01 challenges must be writable under this same root
 * (/.well-known/acme-challenge/).
 */

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "SSL Cave requires PHP 8.1 or newer.\n";
    echo 'This host is running PHP ' . PHP_VERSION . ".\n";
    echo "In cPanel use Software → Select PHP Version (or MultiPHP) for this domain.\n";
    exit(1);
}

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/View.php';

$route = isset($_GET['r']) && is_string($_GET['r']) ? $_GET['r'] : 'home';

try {
    match ($route) {
        'login' => handle_login(),
        'logout' => handle_logout(),
        'sync' => handle_sync(),
        'domain' => handle_domain(),
        'notes' => handle_notes(),
        'jobs' => handle_jobs(),
        'job' => handle_job(),
        'issue' => handle_issue(),
        'renew' => handle_renew(),
        'deploy' => handle_deploy(),
        'cleanup' => handle_cleanup(),
        'settings' => handle_settings(),
        default => handle_home(),
    };
} catch (Throwable $e) {
    http_response_code(500);
    if (class_exists('Auth', false) && Auth::check()) {
        flash('error', $e->getMessage());
        redirect('/');
    }
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Error: ' . $e->getMessage();
}

function handle_home(): void
{
    Auth::requireLogin();
    $pdo = Database::pdo();
    $domains = $pdo->query(
        "SELECT * FROM domains ORDER BY
            FIELD(status, 'job-failed','drift','expiring','job-running','job-pending','acme-only','cpanel-only','ok','unknown'),
            domain ASC"
    )->fetchAll();

    $attention = array_values(array_filter(
        $domains,
        static fn(array $d): bool => !in_array((string) $d['status'], ['ok'], true)
    ));

    $lastJobs = [];
    $jobRows = $pdo->query(
        'SELECT j.id, j.domain, j.action, j.status, j.exit_code, j.finished_at, j.stdout, j.stderr
         FROM jobs j
         INNER JOIN (
             SELECT domain, MAX(id) AS max_id FROM jobs GROUP BY domain
         ) latest ON latest.max_id = j.id'
    )->fetchAll();
    foreach ($jobRows as $job) {
        $lastJobs[(string) $job['domain']] = $job;
    }

    View::render('dashboard', [
        'title' => 'Inventory',
        'domains' => $domains,
        'attention' => $attention,
        'lastJobs' => $lastJobs,
        'lastSync' => Database::setting('last_sync_at'),
    ]);
}

function handle_login(): void
{
    if (Auth::check()) {
        redirect('/');
    }

    if (is_post()) {
        Csrf::requireValid();
        $ip = client_ip();
        if (Auth::isLockedOut($ip)) {
            flash('error', 'Too many failed logins. Try again later.');
            redirect('/?r=login');
        }

        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        if (Auth::attempt($user, $pass)) {
            Auth::clearAttempts($ip);
            redirect('/');
        }

        Auth::recordFailedAttempt($ip);
        flash('error', 'Invalid credentials.');
        redirect('/?r=login');
    }

    View::render('login', [
        'title' => 'Sign in',
        'appName' => (string) config_get('app_name', 'SSL Cave'),
    ], 'layout');
}

function handle_logout(): void
{
    if (is_post()) {
        Csrf::requireValid();
    }
    Auth::logout();
    redirect('/?r=login');
}

function handle_sync(): void
{
    Auth::requireLogin();
    if (!is_post()) {
        redirect('/');
    }
    Csrf::requireValid();

    $result = (new SyncService())->sync();
    $msg = 'Synced ' . $result['domains'] . ' domain(s)';
    if (($result['pruned'] ?? 0) > 0) {
        $msg .= ', pruned ' . $result['pruned'] . ' stale inventory row(s)';
    }
    $msg .= '.';
    if ($result['errors'] !== []) {
        flash('warn', $msg . ' Warnings: ' . implode('; ', $result['errors']));
    } else {
        flash('ok', $msg);
    }
    redirect('/');
}

function handle_domain(): void
{
    Auth::requireLogin();
    $domain = strtolower(trim((string) ($_GET['d'] ?? '')));
    if ($domain === '') {
        redirect('/');
    }

    $stmt = Database::pdo()->prepare('SELECT * FROM domains WHERE domain = ? LIMIT 1');
    $stmt->execute([$domain]);
    $row = $stmt->fetch();
    if ($row === false) {
        flash('error', 'Domain not found.');
        redirect('/');
    }

    $jobs = Database::pdo()->prepare(
        'SELECT * FROM jobs WHERE domain = ? ORDER BY id DESC LIMIT 20'
    );
    $jobs->execute([$domain]);

    View::render('domain', [
        'title' => $domain,
        'domain' => $row,
        'jobs' => $jobs->fetchAll(),
    ]);
}

function handle_notes(): void
{
    Auth::requireLogin();
    if (!is_post()) {
        redirect('/');
    }
    Csrf::requireValid();

    $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $stmt = Database::pdo()->prepare(
        'UPDATE domains SET notes = ?, updated_at = ? WHERE domain = ?'
    );
    $stmt->execute([$notes, now_utc(), $domain]);
    flash('ok', 'Notes saved.');
    redirect('/?r=domain&d=' . urlencode($domain));
}

function handle_jobs(): void
{
    Auth::requireLogin();
    View::render('jobs', [
        'title' => 'Jobs',
        'jobs' => (new JobQueue())->recent(100),
        'workerLastRunAt' => Database::setting('worker_last_run_at'),
        'pendingJobs' => (new JobQueue())->pendingCount(),
    ]);
}

function handle_job(): void
{
    Auth::requireLogin();
    $id = (int) ($_GET['id'] ?? 0);
    $queue = new JobQueue();

    if (is_post()) {
        Csrf::requireValid();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'cancel') {
            try {
                $queue->cancel($id, 'Cancelled from portal (stuck or abandoned).');
                flash('ok', "Job #{$id} marked failed. You can enqueue a new issue/renew.");
            } catch (Throwable $e) {
                flash('error', $e->getMessage());
            }
            redirect('/?r=job&id=' . $id);
        }
        flash('error', 'Unknown action.');
        redirect('/?r=job&id=' . $id);
    }

    $job = $queue->find($id);
    if ($job === null) {
        flash('error', 'Job not found.');
        redirect('/?r=jobs');
    }
    View::render('job', [
        'title' => 'Job #' . $id,
        'job' => $job,
    ]);
}

function handle_issue(): void
{
    Auth::requireLogin();

    if (is_post()) {
        Csrf::requireValid();
        $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
        $webroot = trim((string) ($_POST['webroot'] ?? ''));
        $includeWww = !empty($_POST['include_www']);

        try {
            $id = (new JobQueue())->enqueue($domain, 'issue', [
                'webroot' => $webroot,
                'include_www' => $includeWww,
            ]);
            flash('ok', "Issue job #{$id} queued for {$domain}.");
            redirect('/?r=job&id=' . $id);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            $back = $domain !== '' ? '/?r=issue&d=' . rawurlencode($domain) : '/?r=issue';
            redirect($back);
        }
    }

    $cpanelUser = (string) config_get('paths.cpanel_user', '');
    $prefillDomain = strtolower(trim((string) ($_GET['d'] ?? '')));
    if ($prefillDomain !== '' && !preg_match('/^[a-z0-9.-]+$/', $prefillDomain)) {
        $prefillDomain = '';
    }
    $suggestedWebroot = '';
    if ($prefillDomain !== '' && $cpanelUser !== '') {
        $suggestedWebroot = '/home/' . $cpanelUser . '/' . $prefillDomain;
    }
    // www. only makes sense for apex hosts; leave off for subdomains by default.
    $defaultIncludeWww = $prefillDomain === '' || substr_count($prefillDomain, '.') < 2;

    View::render('issue', [
        'title' => 'Issue',
        'cpanelUser' => $cpanelUser,
        'prefillDomain' => $prefillDomain,
        'suggestedWebroot' => $suggestedWebroot,
        'defaultIncludeWww' => $defaultIncludeWww,
    ]);
}

function handle_renew(): void
{
    Auth::requireLogin();
    if (!is_post()) {
        redirect('/');
    }
    Csrf::requireValid();

    $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
    try {
        $id = (new JobQueue())->enqueue($domain, 'renew');
        flash('ok', "Renew job #{$id} queued for {$domain}.");
        redirect('/?r=job&id=' . $id);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/?r=domain&d=' . urlencode($domain));
    }
}

function handle_deploy(): void
{
    Auth::requireLogin();
    if (!is_post()) {
        redirect('/');
    }
    Csrf::requireValid();

    $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
    try {
        $id = (new JobQueue())->enqueue($domain, 'deploy');
        flash('ok', "Deploy job #{$id} queued for {$domain}.");
        redirect('/?r=job&id=' . $id);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/?r=domain&d=' . urlencode($domain));
    }
}

function handle_cleanup(): void
{
    Auth::requireLogin();
    if (!is_post()) {
        redirect('/');
    }
    Csrf::requireValid();

    $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
    $confirm = strtolower(trim((string) ($_POST['confirm_domain'] ?? '')));
    $inventoryOnly = !empty($_POST['inventory_only']);

    if ($domain === '' || !preg_match('/^[a-z0-9.-]+$/', $domain)) {
        flash('error', 'Invalid domain.');
        redirect('/');
    }
    if ($confirm !== $domain) {
        flash('error', 'Type the domain name exactly to confirm cleanup.');
        redirect('/?r=domain&d=' . urlencode($domain));
    }

    $pdo = Database::pdo();
    $stmt = $pdo->prepare('SELECT * FROM domains WHERE domain = ? LIMIT 1');
    $stmt->execute([$domain]);
    $row = $stmt->fetch();
    if ($row === false) {
        flash('error', 'Domain not found.');
        redirect('/');
    }

    $inAcme = (int) $row['in_acme'] === 1;
    $inCpanel = (int) $row['in_cpanel'] === 1;

    if ($inventoryOnly) {
        if ($inAcme || $inCpanel) {
            flash('error', 'Cannot remove from inventory only while the domain still appears in acme.sh or cPanel. Choose cleanup options instead.');
            redirect('/?r=domain&d=' . urlencode($domain));
        }
        $del = $pdo->prepare('DELETE FROM domains WHERE domain = ?');
        $del->execute([$domain]);
        flash('ok', "Removed {$domain} from portal inventory.");
        redirect('/');
    }

    $fromAcme = !empty($_POST['from_acme']);
    $purgeFiles = !empty($_POST['purge_acme_files']);
    $fromCpanel = !empty($_POST['from_cpanel']);

    if (!$fromAcme && !$purgeFiles && !$fromCpanel) {
        flash('error', 'Select at least one cleanup option.');
        redirect('/?r=domain&d=' . urlencode($domain));
    }

    try {
        $id = (new JobQueue())->enqueue($domain, 'remove', [
            'from_acme' => $fromAcme,
            'purge_acme_files' => $purgeFiles,
            'from_cpanel' => $fromCpanel,
        ]);
        flash('ok', "Cleanup job #{$id} queued for {$domain}. Worker will remove selected sources.");
        redirect('/?r=job&id=' . $id);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/?r=domain&d=' . urlencode($domain));
    }
}

function handle_settings(): void
{
    Auth::requireLogin();

    if (is_post()) {
        Csrf::requireValid();
        $email = trim((string) ($_POST['alert_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid alert email.');
            redirect('/?r=settings');
        }
        Database::setSetting('alert_email', $email);
        flash('ok', 'Settings saved.');
        redirect('/?r=settings');
    }

    $alertEmail = Database::setting('alert_email', (string) config_get('alerts.email', '')) ?? '';
    View::render('settings', [
        'title' => 'Settings',
        'alertEmail' => $alertEmail,
        'lastSync' => Database::setting('last_sync_at'),
    ]);
}
