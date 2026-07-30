#!/bin/sh
set -eu

APP_ROOT=/var/www/html
CONFIG="$APP_ROOT/config.php"

export DB_HOST="${DB_HOST:-db}"
export DB_NAME="${DB_NAME:-sslcave}"
export DB_USER="${DB_USER:-sslcave}"
export DB_PASS="${DB_PASS:-sslcave}"
export SSLCAVE_USERNAME="${SSLCAVE_USERNAME:-admin}"
export SSLCAVE_PASSWORD="${SSLCAVE_PASSWORD:-sslcave}"
export SSLCAVE_BASE_URL="${SSLCAVE_BASE_URL:-http://localhost:8088}"
export SSLCAVE_TIMEZONE="${SSLCAVE_TIMEZONE:-UTC}"
export SSLCAVE_ALERT_EMAIL="${SSLCAVE_ALERT_EMAIL:-}"

mkdir -p "$APP_ROOT/storage/logs" "$APP_ROOT/storage/sessions"
chown -R www-data:www-data "$APP_ROOT/storage" 2>/dev/null || true

# Make fixture CLIs executable when mounted from Windows/macOS hosts.
chmod +x "$APP_ROOT/docker/fixtures/bin/acme.sh" "$APP_ROOT/docker/fixtures/bin/uapi" 2>/dev/null || true

if [ ! -f "$CONFIG" ] || [ "${SSLCAVE_FORCE_CONFIG:-0}" = "1" ]; then
  php <<'PHP'
<?php
$config = [
    'app_name' => 'SSL Cave',
    'base_url' => getenv('SSLCAVE_BASE_URL') ?: 'http://localhost:8088',
    'timezone' => getenv('SSLCAVE_TIMEZONE') ?: 'UTC',
    'auth' => [
        'username' => getenv('SSLCAVE_USERNAME') ?: 'admin',
        'password_hash' => password_hash(getenv('SSLCAVE_PASSWORD') ?: 'sslcave', PASSWORD_DEFAULT),
        'session_name' => 'sslcave_sess',
        'lockout_max_attempts' => 5,
        'lockout_window_seconds' => 900,
    ],
    'db' => [
        'dsn' => sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: 'db',
            getenv('DB_NAME') ?: 'sslcave'
        ),
        'user' => getenv('DB_USER') ?: 'sslcave',
        'pass' => getenv('DB_PASS') ?: 'sslcave',
    ],
    'paths' => [
        'cpanel_user' => 'docker',
        'home' => '/var/www/html',
        'acme_home' => '/var/www/html/docker/fixtures/acme.sh',
        'acme_bin' => '/var/www/html/docker/fixtures/bin/acme.sh',
        'uapi_bin' => '/var/www/html/docker/fixtures/bin/uapi',
        'storage' => '__DIR__ . \'/storage\'',
    ],
    'alerts' => [
        'email' => getenv('SSLCAVE_ALERT_EMAIL') ?: '',
        'from' => 'sslcave@localhost',
        'expiry_days' => [30, 14, 7],
    ],
    'jobs' => [
        'allowed_actions' => ['issue', 'renew', 'deploy', 'remove'],
        'max_runtime_seconds' => 600,
        'log_dir' => '__DIR__ . \'/storage/logs\'',
    ],
];

$export = var_export($config, true);
$export = str_replace("'__DIR__ . \\'/storage\\''", "__DIR__ . '/storage'", $export);
$export = str_replace("'__DIR__ . \\'/storage/logs\\''", "__DIR__ . '/storage/logs'", $export);

file_put_contents('/var/www/html/config.php', "<?php\nreturn " . $export . ";\n");
echo "Wrote /var/www/html/config.php for Docker local use.\n";
PHP
fi

echo "Waiting for MySQL at ${DB_HOST}..."
i=0
until php -r '
try {
  new PDO(
    sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", getenv("DB_HOST"), getenv("DB_NAME")),
    getenv("DB_USER"),
    getenv("DB_PASS"),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
  exit(0);
} catch (Throwable $e) {
  exit(1);
}
' 2>/dev/null; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    echo "MySQL not ready after 60s." >&2
    exit 1
  fi
  sleep 1
done
echo "MySQL is ready."

exec "$@"
