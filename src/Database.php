<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = config_get('db');
        if (!is_array($cfg)) {
            throw new RuntimeException('Database config missing.');
        }

        self::$pdo = new PDO(
            (string) $cfg['dsn'],
            (string) $cfg['user'],
            (string) $cfg['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return self::$pdo;
    }

    public static function setting(string $key, ?string $default = null): ?string
    {
        $stmt = self::pdo()->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row === false) {
            return $default;
        }
        return (string) $row['value'];
    }

    public static function setSetting(string $key, string $value): void
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        $stmt->execute([$key, $value]);
    }
}
