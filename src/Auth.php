<?php

declare(strict_types=1);

final class Auth
{
    public static function startSession(): void
    {
        $name = (string) config_get('auth.session_name', 'sslcave_sess');
        $storage = (string) config_get('paths.storage', dirname(__DIR__) . '/storage');
        $sessionPath = $storage . '/sessions';
        if (is_dir($sessionPath) && is_writable($sessionPath)) {
            session_save_path($sessionPath);
        }

        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function check(): bool
    {
        return !empty($_SESSION['auth_user']) && is_string($_SESSION['auth_user']);
    }

    public static function user(): ?string
    {
        return self::check() ? (string) $_SESSION['auth_user'] : null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('/?r=login');
        }
    }

    public static function isLockedOut(string $ip): bool
    {
        $max = (int) config_get('auth.lockout_max_attempts', 5);
        $window = (int) config_get('auth.lockout_window_seconds', 900);
        $since = gmdate('Y-m-d H:i:s', time() - $window);

        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND attempted_at >= ?'
        );
        $stmt->execute([$ip, $since]);
        $count = (int) ($stmt->fetch()['c'] ?? 0);
        return $count >= $max;
    }

    public static function recordFailedAttempt(string $ip): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)'
        );
        $stmt->execute([$ip, now_utc()]);
    }

    public static function clearAttempts(string $ip): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM login_attempts WHERE ip = ?');
        $stmt->execute([$ip]);
    }

    public static function attempt(string $username, string $password): bool
    {
        $expectedUser = (string) config_get('auth.username', '');
        $hash = (string) config_get('auth.password_hash', '');

        if ($expectedUser === '' || $hash === '') {
            return false;
        }

        $userOk = hash_equals($expectedUser, $username);
        $passOk = password_verify($password, $hash);

        if ($userOk && $passOk) {
            session_regenerate_id(true);
            $_SESSION['auth_user'] = $expectedUser;
            return true;
        }

        return false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }
}
